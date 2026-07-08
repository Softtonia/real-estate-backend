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
        $query = Keyword::with([
            'postType:id,name,slug',
            'dynamicPost:id,post_type_id,title,slug',
        ]);

        $query->when($request->filled('keyword_type'), fn ($q) => $q->where('keyword_type', $request->keyword_type));
        $query->when($request->filled('post_type_id'), fn ($q) => $q->where('post_type_id', $request->post_type_id));
        $query->when($request->filled('dynamic_post_id'), fn ($q) => $q->where('dynamic_post_id', $request->dynamic_post_id));

        $includeSystem = $request->boolean('include_system', true);

        $fileName = 'keywords-export-' . now()->format('YmdHis') . '.csv';

        return response()->streamDownload(function () use ($query, $includeSystem) {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");

            $headers = $this->headers($includeSystem);

            fputcsv($handle, $headers);

            $query->orderBy('id')->chunk(500, function ($keywords) use ($handle, $includeSystem) {
                foreach ($keywords as $keyword) {
                    fputcsv($handle, $this->row($keyword, $includeSystem));
                }
            });

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    public function template(Request $request): StreamedResponse
    {
        $includeSystem = $request->boolean('include_system', true);

        return response()->streamDownload(function () use ($includeSystem) {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $this->headers($includeSystem));

            if ($includeSystem) {
                fputcsv($handle, [
                    '',
                    '',
                    'property-listing-main-keywords',
                    'post_type',
                    '',
                    'property-listing',
                    '',
                    '',
                    'property in mohali, flats in chandigarh, luxury apartment',
                ]);
            } else {
                fputcsv($handle, [
                    'property-listing-main-keywords',
                    'post_type',
                    '',
                    'property-listing',
                    '',
                    '',
                    'property in mohali, flats in chandigarh, luxury apartment',
                ]);
            }

            fclose($handle);
        }, 'keywords-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    private function headers(bool $includeSystem): array
    {
        $headers = [
            'slug',
            'keyword_type',
            'post_type_id',
            'post_type_slug',
            'dynamic_post_id',
            'dynamic_post_slug',
            'keyword_list',
        ];

        if ($includeSystem) {
            array_unshift($headers, 'id', 'import_uid');
        }

        return $headers;
    }

    private function row(Keyword $keyword, bool $includeSystem): array
    {
        $row = [
            $keyword->slug,
            $keyword->keyword_type,
            $keyword->post_type_id,
            $keyword->postType?->slug,
            $keyword->dynamic_post_id,
            $keyword->dynamicPost?->slug,
            implode(', ', $keyword->keyword_list ?? []),
        ];

        if ($includeSystem) {
            array_unshift($row, $keyword->id, $keyword->import_uid);
        }

        return $row;
    }
}