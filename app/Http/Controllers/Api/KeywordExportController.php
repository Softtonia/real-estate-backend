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

        $fileName = 'keywords-export-' . now()->format('YmdHis') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $this->headers());

            $query->orderBy('id')->chunk(500, function ($keywords) use ($handle) {
                foreach ($keywords as $keyword) {
                    fputcsv($handle, [
                        $keyword->id,
                        $keyword->import_uid,
                        $keyword->slug,
                        $keyword->keyword_type,
                        $keyword->post_type_id,
                        $keyword->postType?->slug,
                        $keyword->dynamic_post_id,
                        $keyword->dynamicPost?->slug,
                        implode(', ', $keyword->keyword_list ?? []),
                        $keyword->search_volume,
                        $keyword->ranking,
                        $keyword->intent,
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

            fputcsv($handle, $this->headers());

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
                '1000',
                '1',
                'commercial',
            ]);

            fputcsv($handle, [
                '',
                '',
                'luxury-property-keywords',
                'dynamic_post',
                '',
                'property-listing',
                '25',
                '',
                'luxury flat, 3 bhk flat, ready to move property',
                '800',
                '2',
                'transactional',
            ]);

            fclose($handle);
        }, 'sample_keywords.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    private function headers(): array
    {
        return [
            'id',
            'import_uid',
            'slug',
            'keyword_type',
            'post_type_id',
            'post_type_slug',
            'dynamic_post_id',
            'dynamic_post_slug',
            'keyword_list',
            'search_volume',
            'ranking',
            'intent',
        ];
    }
}