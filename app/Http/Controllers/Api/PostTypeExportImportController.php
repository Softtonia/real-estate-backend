<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PostType;
use App\Models\Taxonomy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class PostTypeExportImportController extends Controller
{
    /**
     * Import Post Types from CSV
     * CSV Format: name, slug, description, is_default, status, supports, sort_order, menu_order, taxonomies
     * - supports: JSON array like '["title","editor","thumbnail"]'
     * - taxonomies: Comma-separated taxonomy slugs like 'category,post_tag'
     */
    public function importFromCsv(Request $request)
    {
        try {
            $request->validate([
                'csv_file' => 'required|file|mimes:csv,txt',
            ]);

            $file = $request->file('csv_file');
            $excelData = Excel::toArray([], $file);

            if (empty($excelData) || empty($excelData[0])) {
                return response()->json([
                    'status' => false,
                    'message' => 'CSV file is empty.',
                ], 422);
            }

            $rows = $excelData[0];
            $header = $rows[0] ?? [];

            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];

            DB::beginTransaction();

            foreach (array_slice($rows, 1) as $index => $row) {
                $rowNumber = $index + 2;

                // Skip empty rows
                if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
                    $skipped++;
                    continue;
                }

                // Expected columns: name, slug, description, is_default, status, supports, sort_order, menu_order, taxonomies
                $expectedColumnCount = 9;
                if (count($row) < $expectedColumnCount) {
                    $row = array_pad($row, $expectedColumnCount, null);
                }

                $name = trim((string)($row[0] ?? ''));
                $slug = trim((string)($row[1] ?? ''));
                $description = trim((string)($row[2] ?? ''));
                $isDefault = trim((string)($row[3] ?? ''));
                $status = trim((string)($row[4] ?? ''));
                $supportsJson = trim((string)($row[5] ?? ''));
                $sortOrder = $row[6] ?? null;
                $menuOrder = $row[7] ?? null;
                $taxonomiesStr = trim((string)($row[8] ?? ''));

                if (empty($name)) {
                    $skipped++;
                    $errors[] = [
                        'row_number' => $rowNumber,
                        'message' => 'Post type name is required.',
                    ];
                    continue;
                }

                // Generate slug if empty
                if (empty($slug)) {
                    $slug = PostType::generateUniqueSlug($name);
                }

                // Parse supports JSON
                $supports = [];
                if (!empty($supportsJson)) {
                    $supports = json_decode($supportsJson, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $supports = array_map('trim', explode(',', $supportsJson));
                    }
                }

                // Parse taxonomies
                $taxonomyIds = [];
                if (!empty($taxonomiesStr)) {
                    $taxonomySlugs = array_map('trim', explode(',', $taxonomiesStr));
                    $taxonomyIds = Taxonomy::whereIn('slug', $taxonomySlugs)->pluck('id')->toArray();
                }

                // Create or update post type
                $existingPostType = PostType::withTrashed()->where('slug', $slug)->first();
                
                $postType = PostType::updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $name,
                        'description' => $description ?: null,
                        'is_default' => filter_var($isDefault, FILTER_VALIDATE_BOOLEAN),
                        'status' => filter_var($status, FILTER_VALIDATE_BOOLEAN) ?: true,
                        'supports' => $supports,
                        'sort_order' => $sortOrder ?: null,
                        'menu_order' => $menuOrder ?: null,
                        'created_by' => Auth::id(),
                    ]
                );

                // Attach taxonomies if provided
                if (!empty($taxonomyIds)) {
                    $postType->taxonomies()->sync($taxonomyIds);
                }

                if ($existingPostType) {
                    $updated++;
                } else {
                    $created++;
                }
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Post types imported successfully!',
                'summary' => [
                    'created' => $created,
                    'updated' => $updated,
                    'skipped' => $skipped,
                ],
                'errors' => $errors,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Import failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export Post Types to CSV
     */
    public function exportToCsv()
    {
        try {
            $postTypes = PostType::with('taxonomies')->get();

            if ($postTypes->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No post types found to export.',
                ], 404);
            }

            $rows = [];
            foreach ($postTypes as $postType) {
                $supports = !empty($postType->supports) ? json_encode($postType->supports) : '';
                $taxonomies = $postType->taxonomies->pluck('slug')->implode(', ');

                $rows[] = [
                    'name' => $postType->name,
                    'slug' => $postType->slug,
                    'description' => $postType->description,
                    'is_default' => $postType->is_default,
                    'status' => $postType->status,
                    'supports' => $supports,
                    'sort_order' => $postType->sort_order,
                    'menu_order' => $postType->menu_order,
                    'taxonomies' => $taxonomies,
                ];
            }

            $fileName = 'post_types_export_' . date('Ymd_His') . '.csv';
            $filePath = storage_path("app/public/postTypes/" . $fileName);

            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0755, true);
            }

            $df = fopen($filePath, 'w');
            fputcsv($df, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($df, $row);
            }
            fclose($df);

            return response()->download($filePath)->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}