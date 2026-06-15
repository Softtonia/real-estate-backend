<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Taxonomy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class TaxonomyExportImportController extends Controller
{
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

                // Expected columns: name, slug, description, is_default, hierarchical, status, sort_order
                $expectedColumnCount = 7;
                if (count($row) < $expectedColumnCount) {
                    $row = array_pad($row, $expectedColumnCount, null);
                }

                $name = trim((string)($row[0] ?? ''));
                $slug = trim((string)($row[1] ?? ''));
                $description = trim((string)($row[2] ?? ''));
                $isDefault = trim((string)($row[3] ?? ''));
                $hierarchical = trim((string)($row[4] ?? ''));
                $status = trim((string)($row[5] ?? ''));
                $sortOrder = $row[6] ?? null;

                if (empty($name)) {
                    $skipped++;
                    $errors[] = [
                        'row_number' => $rowNumber,
                        'message' => 'Taxonomy name is required.',
                    ];
                    continue;
                }

                // Generate slug if empty
                if (empty($slug)) {
                    $slug = Str::slug($name);
                    if (Taxonomy::withTrashed()->where('slug', $slug)->exists()) {
                        $slug .= '-' . time();
                    }
                }

                // Create or update taxonomy
                $existingTaxonomy = Taxonomy::withTrashed()->where('slug', $slug)->first();
                
                $taxonomy = Taxonomy::updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $name,
                        'description' => $description ?: null,
                        'is_default' => filter_var($isDefault, FILTER_VALIDATE_BOOLEAN),
                        'hierarchical' => filter_var($hierarchical, FILTER_VALIDATE_BOOLEAN),
                        'status' => filter_var($status, FILTER_VALIDATE_BOOLEAN) ?: true,
                        'sort_order' => $sortOrder ?: null,
                        'created_by' => Auth::id(),
                    ]
                );

                if ($existingTaxonomy) {
                    $updated++;
                } else {
                    $created++;
                }
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Taxonomies imported successfully!',
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

    public function exportToCsv()
    {
        try {
            $taxonomies = Taxonomy::all();

            if ($taxonomies->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No taxonomies found to export.',
                ], 404);
            }

            $rows = [];
            foreach ($taxonomies as $taxonomy) {
                $rows[] = [
                    'name' => $taxonomy->name,
                    'slug' => $taxonomy->slug,
                    'description' => $taxonomy->description,
                    'is_default' => $taxonomy->is_default,
                    'hierarchical' => $taxonomy->hierarchical,
                    'status' => $taxonomy->status,
                    'sort_order' => $taxonomy->sort_order,
                ];
            }

            $fileName = 'taxonomies_export_' . date('Ymd_His') . '.csv';
            $filePath = storage_path("app/public/taxonomies/" . $fileName);

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