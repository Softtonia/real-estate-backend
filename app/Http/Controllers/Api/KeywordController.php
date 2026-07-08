<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\Models\Keyword;
use App\Models\PostType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class KeywordController extends Controller
{
    /**
     * Allowed values for the keyword_type discriminator column.
     */
    private const KEYWORD_TYPES = ['posttype', 'listing'];

    public function index(Request $request): JsonResponse
    {
        try {
            $query = Keyword::query()
                ->with(['postType:id,name,slug', 'dynamicPost:id,title,slug'])
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = trim($request->input('search'));
                    $q->where(function ($sub) use ($search) {
                        $sub->where('slug', 'like', "%{$search}%")
                            ->orWhere('keyword_type', 'like', "%{$search}%")
                            ->orWhereJsonContains('keyword_list', $search);
                    });
                })
                ->when($request->filled('keyword_type'), function ($q) use ($request) {
                    $q->where('keyword_type', $request->input('keyword_type'));
                })
                ->when($request->filled('post_type_id'), function ($q) use ($request) {
                    $q->where('post_type_id', (int) $request->input('post_type_id'));
                })
                ->when($request->filled('dynamic_post_id'), function ($q) use ($request) {
                    $q->where('dynamic_post_id', (int) $request->input('dynamic_post_id'));
                })
                ->orderBy('source_row_number')
                ->orderBy('id');

            $perPage = min((int) $request->get('per_page', 15), 100);
            $keywords = $query->paginate($perPage);

            // source_row_number is hidden by model $hidden property

            return response()->json([
                'status' => true,
                'message' => 'Keywords retrieved successfully.',
                'data' => $keywords,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch keywords.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'slug' => 'required|string|max:255|unique:keywords,slug',
                'keyword_type' => ['required', Rule::in(self::KEYWORD_TYPES)],
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'dynamic_post_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],
                'keyword_list' => 'required',
            ]);

            $keywordList = Keyword::normalizeKeywordList($request->input('keyword_list'));

            $keyword = Keyword::create([
                'slug' => $validated['slug'],
                'keyword_type' => $validated['keyword_type'],
                'post_type_id' => $validated['post_type_id'] ?? null,
                'dynamic_post_id' => $validated['dynamic_post_id'] ?? null,
                'keyword_list' => $keywordList,
                'source_row_number' => null,
            ]);

            $keyword->load(['postType:id,name,slug', 'dynamicPost:id,title,slug']);

            return response()->json([
                'status' => true,
                'message' => 'Keyword created successfully.',
                'data' => $keyword,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create keyword.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $keyword = Keyword::with(['postType:id,name,slug', 'dynamicPost:id,title,slug'])
                ->findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Keyword retrieved successfully.',
                'data' => $keyword,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Keyword not found.',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $keyword = Keyword::findOrFail($id);

            $validated = $request->validate([
                'slug' => ['required', 'string', 'max:255', Rule::unique('keywords', 'slug')->ignore($id)],
                'keyword_type' => ['required', Rule::in(self::KEYWORD_TYPES)],
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'dynamic_post_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],
                'keyword_list' => 'nullable',
            ]);

            $keywordList = Keyword::normalizeKeywordList($request->input('keyword_list', $keyword->keyword_list));

            $keyword->update([
                'slug' => $validated['slug'],
                'keyword_type' => $validated['keyword_type'],
                'post_type_id' => $validated['post_type_id'] ?? $keyword->post_type_id,
                'dynamic_post_id' => array_key_exists('dynamic_post_id', $validated)
                    ? ($validated['dynamic_post_id'] ?? null)
                    : $keyword->dynamic_post_id,
                'keyword_list' => $keywordList,
            ]);

            $keyword->load(['postType:id,name,slug', 'dynamicPost:id,title,slug']);

            return response()->json([
                'status' => true,
                'message' => 'Keyword updated successfully.',
                'data' => $keyword,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update keyword.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $keyword = Keyword::findOrFail($id);
            $keyword->delete();

            return response()->json([
                'status' => true,
                'message' => 'Keyword deleted successfully.',
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete keyword.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolve a post type slug/name to its id (used by import).
     */
    private function resolvePostTypeId(?string $postType): ?int
    {
        if (empty($postType)) {
            return null;
        }

        $postType = trim($postType);

        return PostType::where('slug', $postType)
            ->orWhere('name', $postType)
            ->value('id');
    }

    /**
     * Import keywords from CSV/XLSX.
     * File columns: slug, keyword_type, post_type, dynamic_post_id, keyword_list
     *
     * - post_type is a slug/name resolved to post_type_id (relational to post_types).
     * - dynamic_post_id is an integer id referencing dynamic_posts/listings.
     *
     * Matching priority:
     * 1. Match by keyword_type + post_type_id + source_row_number
     * 2. If not found, match by slug
     * 3. If not found, create new
     */
    public function import(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:csv,txt,xlsx,xls',
            ]);

            $file = $request->file('file');
            $extension = $file->getClientOriginalExtension();

            if (in_array($extension, ['xlsx', 'xls'])) {
                if (!class_exists('Maatwebsite\Excel\Facades\Excel')) {
                    return response()->json([
                        'status' => false,
                        'message' => 'XLSX import requires maatwebsite/excel package. Use CSV instead.',
                    ], 422);
                }

                $excelData = \Maatwebsite\Excel\Facades\Excel::toArray([], $file);
                $rows = $excelData[0] ?? [];
            } else {
                $handle = fopen($file->getRealPath(), 'r');
                $rows = [];
                while (($row = fgetcsv($handle)) !== false) {
                    $rows[] = $row;
                }
                fclose($handle);
            }

            if (empty($rows) || count($rows) < 2) {
                return response()->json([
                    'status' => false,
                    'message' => 'File is empty or has no data rows.',
                ], 422);
            }

            $header = array_map('trim', $rows[0]);
            $slugIdx = array_search('slug', $header);
            $typeIdx = array_search('keyword_type', $header);
            $postTypeIdx = array_search('post_type', $header);
            $dynPostIdx = array_search('dynamic_post_id', $header);
            $listIdx = array_search('keyword_list', $header);

            if ($slugIdx === false && $postTypeIdx === false && $dynPostIdx === false) {
                return response()->json([
                    'status' => false,
                    'message' => 'File must contain at least one of "slug", "post_type" or "dynamic_post_id" column.',
                ], 422);
            }

            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];

            DB::beginTransaction();

            foreach (array_slice($rows, 1) as $csvRowIndex => $row) {
                $sourceRowNumber = $csvRowIndex + 2; // 1-based after header

                // Skip completely empty rows
                if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) {
                    $skipped++;
                    continue;
                }

                $slug = $slugIdx !== false ? trim((string)($row[$slugIdx] ?? '')) : '';
                $keywordType = $typeIdx !== false ? trim((string)($row[$typeIdx] ?? '')) : '';
                $postType = $postTypeIdx !== false ? trim((string)($row[$postTypeIdx] ?? '')) : '';
                $dynamicPostId = $dynPostIdx !== false ? trim((string)($row[$dynPostIdx] ?? '')) : '';
                $keywordListRaw = $listIdx !== false ? trim((string)($row[$listIdx] ?? '')) : '';

                $keywordType = $keywordType ?: 'posttype';
                if (!in_array($keywordType, self::KEYWORD_TYPES, true)) {
                    $keywordType = 'posttype';
                }

                $keywordList = Keyword::normalizeKeywordList($keywordListRaw);

                $postTypeId = $this->resolvePostTypeId($postType);

                $dynamicPostId = $dynamicPostId !== '' ? (int) $dynamicPostId : null;
                if ($dynamicPostId !== null && !DynamicPost::where('id', $dynamicPostId)->exists()) {
                    $dynamicPostId = null;
                }

                // Generate slug if empty
                if (empty($slug)) {
                    if (empty($postType) && empty($dynamicPostId)) {
                        $skipped++;
                        $errors[] = [
                            'source_row_number' => $sourceRowNumber,
                            'message' => 'Either slug, post_type or dynamic_post_id is required.',
                        ];
                        continue;
                    }
                    $slug = Keyword::generateSlugFromPostType(
                        $postType ?: ('listing-' . $dynamicPostId),
                        $sourceRowNumber
                    );
                }

                // Matching priority:
                // 1. Match by keyword_type + post_type_id + source_row_number
                // 2. If not found, match by slug
                // 3. If not found, create new
                $existingBySource = null;
                if (!empty($keywordType) && (!empty($postTypeId) || !empty($dynamicPostId))) {
                    $existingBySource = Keyword::where('keyword_type', $keywordType)
                        ->when($postTypeId, fn($q) => $q->where('post_type_id', $postTypeId))
                        ->when($dynamicPostId, fn($q) => $q->where('dynamic_post_id', $dynamicPostId))
                        ->where('source_row_number', $sourceRowNumber)
                        ->first();
                }

                $existingBySlug = Keyword::where('slug', $slug)->first();

                if ($existingBySource) {
                    $existingBySource->update([
                        'slug' => $slug,
                        'keyword_type' => $keywordType,
                        'post_type_id' => $postTypeId,
                        'dynamic_post_id' => $dynamicPostId,
                        'keyword_list' => $keywordList,
                        'source_row_number' => $sourceRowNumber,
                    ]);
                    $updated++;
                } elseif ($existingBySlug) {
                    $existingBySlug->update([
                        'keyword_type' => $keywordType,
                        'post_type_id' => $postTypeId,
                        'dynamic_post_id' => $dynamicPostId,
                        'keyword_list' => $keywordList,
                        'source_row_number' => $sourceRowNumber,
                    ]);
                    $updated++;
                } else {
                    Keyword::create([
                        'slug' => $slug,
                        'keyword_type' => $keywordType,
                        'post_type_id' => $postTypeId,
                        'dynamic_post_id' => $dynamicPostId,
                        'keyword_list' => $keywordList,
                        'source_row_number' => $sourceRowNumber,
                    ]);
                    $created++;
                }
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Keywords imported successfully.',
                'summary' => [
                    'created' => $created,
                    'updated' => $updated,
                    'skipped' => $skipped,
                ],
                'errors' => $errors,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Import failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export keywords to CSV.
     * Columns: slug, keyword_type, post_type, dynamic_post_id, keyword_list
     * Excludes: id, source_row_number, created_at, updated_at
     */
    public function export(Request $request): JsonResponse
    {
        try {
            $keywords = Keyword::query()
                ->with(['postType:id,name,slug'])
                ->orderBy('source_row_number')
                ->orderBy('id')
                ->get();

            if ($keywords->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No keywords found to export.',
                ], 404);
            }

            $fileName = 'keywords_export_' . date('Ymd_His') . '.csv';
            $filePath = storage_path("app/public/keywords/" . $fileName);

            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0755, true);
            }

            $df = fopen($filePath, 'w');

            fputcsv($df, ['slug', 'keyword_type', 'post_type', 'dynamic_post_id', 'keyword_list']);

            foreach ($keywords as $keyword) {
                $keywordListStr = is_array($keyword->keyword_list)
                    ? implode(', ', $keyword->keyword_list)
                    : (string) $keyword->keyword_list;

                fputcsv($df, [
                    $keyword->slug,
                    $keyword->keyword_type,
                    $keyword->postType ? $keyword->postType->slug : '',
                    $keyword->dynamic_post_id ?? '',
                    $keywordListStr,
                ]);
            }

            fclose($df);

            return response()->download($filePath)->deleteFileAfterSend(true);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Export failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}