<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Keyword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KeywordExportController extends Controller
{
    public function export(Request $request): StreamedResponse
    {
        $query = Keyword::query();

        $query->when($request->filled('keyword_type'), fn ($q) => $q->where('keyword_type', $request->keyword_type));
        $query->when($request->filled('post_type'), fn ($q) => $q->where('post_type', $request->post_type));

        $fileName = 'keywords-export-' . now()->format('YmdHis') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'id',
                'slug',
                'keyword_type',
                'post_type',
                'keyword_list',
            ]);

            $query->orderBy('id')->chunk(500, function ($keywords) use ($handle) {
                foreach ($keywords as $keyword) {
                    fputcsv($handle, [
                        $keyword->id,
                        $keyword->slug,
                        $keyword->keyword_type,
                        $keyword->post_type,
                        implode(', ', $keyword->keyword_list ?? []),
                    ]);
                }
            });

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'id',
                'slug',
                'keyword_type',
                'post_type',
                'keyword_list',
            ]);

            fputcsv($handle, [
                '',
                'property-listing-main-keywords',
                '1',
                '25',
                'property in mohali, flats in chandigarh, luxury apartment',
            ]);

            fclose($handle);
        }, 'sample_keywords.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }
}