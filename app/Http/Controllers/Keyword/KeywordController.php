<?php

namespace App\Http\Controllers\Keyword;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
// use Illuminate\Support\Str;
use App\Models\ImportKeyword;
use Illuminate\Support\Facades\DB;
use Auth;
use Str;
use Hash;
use App\Imports\ImportKeywordsImport;
use App\Exports\ImportKeywordsExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Response;
// use Illuminate\Support\Facades\Validator;
// use Illuminate\Support\Carbon;
use Carbon\Carbon;


class KeywordController extends Controller
{
    //

     // import keywords store function

   


    public function import(Request $request)
    {
        //  Validate CSV file input
        Validator::validate($request->all(), [
            'csv_file' => 'required|file|mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel|max:5120',
        ]);

        try {
            $file = $request->file('csv_file');
            $handle = fopen($file->getRealPath(), 'r');

            //  Skip header row (since your CSV has a header)
            fgetcsv($handle);

            $created = 0;
            $updated = 0;
            $errors = [];

            //  Allowed keyword types
            $allowedTypes = ['property_keyword', 'project_keyword', 'developer_keyword'];

            while (($cols = fgetcsv($handle, 0, ',')) !== false) {
                // Skip empty or invalid rows
                if (count($cols) < 4) {
                    $errors[] = "Invalid column count – row skipped";
                    continue;
                }

                //  Map CSV columns
                $id          = trim($cols[0] ?? '');
                $keywordName = trim($cols[1] ?? '');
                $slug        = trim($cols[2] ?? '');
                $keywordType = trim($cols[3] ?? '');
                $createdAt   = trim($cols[4] ?? now());
                $updatedAt   = trim($cols[5] ?? now());

                //  Basic validation
                if ($keywordName === '' || $slug === '') {
                    $errors[] = "Missing keyword_name or slug at row ID {$id}";
                    continue;
                }

                if (!in_array($keywordType, $allowedTypes)) {
                    $errors[] = "Invalid keyword_type '{$keywordType}' for '{$keywordName}' – row skipped";
                    continue;
                }

                //  Upsert using slug as unique key
                $model = ImportKeyword::updateOrCreate(
                    ['slug' => $slug],
                    [
                        'keyword_name' => $keywordName,
                        'keyword_type' => $keywordType,
                        'created_at'   => $createdAt,
                        'updated_at'   => $updatedAt,
                    ]
                );

                $model->wasRecentlyCreated ? $created++ : $updated++;
            }

            fclose($handle);

            //  Return structured response
            return response()->json([
                'status'  => true,
                'message' => 'CSV processed successfully.',
                'created' => $created,
                'updated' => $updated,
                'skipped' => count($errors),
                'errors'  => $errors,
            ]);

        } catch (\Throwable $e) {
            \Log::error('Import error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'status'  => false,
                'message' => 'Import failed.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // export keywords csv

    public function export(Request $request)
    {
        $fileName = 'import_keywords_' . now()->format('Ymd_His') . '.csv';


        // Get keyword_type filter from request
        $keywordType = $request->input('keyword_type');
        // Build query with optional filtering
        $query = ImportKeyword::query();

        if (!empty($keywordType)) {
            $query->where('keyword_type', $keywordType);
        }

        $keywords = $query->get();


        if ($keywords->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No keywords found to export.',
            ], 404);
        }

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$fileName\"",
        ];

        $columns = ['id', 'keyword_name', 'slug', 'keyword_type', 'created_at', 'updated_at'];

        $callback = function () use ($keywords, $columns) {
            $file = fopen('php://output', 'w');

            // Add CSV header
            fputcsv($file, $columns);

            // Add rows
            foreach ($keywords as $keyword) {
                fputcsv($file, [
                    $keyword->id,
                    $keyword->keyword_name,
                    $keyword->slug,
                    $keyword->keyword_type,
                    $keyword->created_at,
                    $keyword->updated_at
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }


    public function fetchKeywordList(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10); // Default to 10 items per page
            $page = $request->input('page', 1); // Default to first page

            $keywords = ImportKeyword::select(
                'id',
                'keyword_name',
                'slug',
                'keyword_type',
                DB::raw('DATE(created_at) as created_date'),
                DB::raw('DATE(updated_at) as updated_date')
            )
                ->orderBy('keyword_name')
                ->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'message' => $keywords->isEmpty()
                    ? 'No import keywords found.'
                    : 'Import keywords fetched successfully.',
                'total' => $keywords->total(),
                'per_page' => $keywords->perPage(),
                'current_page' => $keywords->currentPage(),
                'last_page' => $keywords->lastPage(),
                'from' => $keywords->firstItem(),
                'to' => $keywords->lastItem(),
                'data' => $keywords->items(),
                'links' => [
                    'first_page_url' => $keywords->url(1),
                    'last_page_url' => $keywords->url($keywords->lastPage()),
                    'prev_page_url' => $keywords->previousPageUrl(),
                    'next_page_url' => $keywords->nextPageUrl(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch keywords.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function searchKeywordList(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10); // Default items per page
            $page = $request->input('page', 1); // Default page number
            $search = trim($request->input('search', '')); // Single search input

            $query = ImportKeyword::select(
                'id',
                'keyword_name',
                'slug',
                'keyword_type',
                DB::raw('DATE(created_at) as created_date'),
                DB::raw('DATE(updated_at) as updated_date')
            );

            // ✅ Apply search filter if search input is not empty
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('keyword_name', 'LIKE', "%{$search}%")
                    ->orWhere('slug', 'LIKE', "%{$search}%");
                });
            }

            // ✅ Order by keyword name
            $keywords = $query
                ->orderBy('keyword_name')
                ->paginate($perPage, ['*'], 'page', $page);

            // ✅ Response
            return response()->json([
                'status' => true,
                'message' => $keywords->isEmpty()
                    ? 'No import keywords found.'
                    : 'Import keywords fetched successfully.',
                'total' => $keywords->total(),
                'per_page' => $keywords->perPage(),
                'current_page' => $keywords->currentPage(),
                'last_page' => $keywords->lastPage(),
                'from' => $keywords->firstItem(),
                'to' => $keywords->lastItem(),
                'data' => $keywords->items(),
                'links' => [
                    'first_page_url' => $keywords->url(1),
                    'last_page_url' => $keywords->url($keywords->lastPage()),
                    'prev_page_url' => $keywords->previousPageUrl(),
                    'next_page_url' => $keywords->nextPageUrl(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch keywords.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



}
