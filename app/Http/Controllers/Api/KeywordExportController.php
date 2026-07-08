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
        $query = Keyword::query()
            ->with([
                'postTypes:id,name,slug',
                'dynamicPosts:id,post_type_id,title,slug',
            ]);

        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->status));

        $query->when($request->filled('keyword_type'), function ($q) use ($request) {
            $value = $request->keyword_type;

            $q->whereHas('postTypes', function ($sub) use ($value) {
                if (is_numeric($value)) {
                    $sub->where('post_types.id', (int) $value);
                } else {
                    $sub->where('post_types.slug', $value)
                        ->orWhere('post_types.name', $value);
                }
            });
        });

        $query->when($request->filled('post_type'), function ($q) use ($request) {
            $value = $request->post_type;

            $q->whereHas('dynamicPosts', function ($sub) use ($value) {
                if (is_numeric($value)) {
                    $sub->where('dynamic_posts.id', (int) $value);
                } else {
                    $sub->where('dynamic_posts.slug', $value)
                        ->orWhere('dynamic_posts.title', $value);
                }
            });
        });

        $fileName = 'keywords-export-' . now()->format('YmdHis') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $this->headers());

            $query->orderBy('id')->chunk(500, function ($keywords) use ($handle) {
                foreach ($keywords as $keyword) {
                    $keywordType = $keyword->postTypes->first();
                    $postType = $keyword->dynamicPosts->first();

                    fputcsv($handle, [
                        $keyword->keyword,
                        $keywordType?->slug,
                        $postType?->slug,
                        $keyword->status,
                        $keyword->avg_search_volume,
                        $keyword->avg_ranking,
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
                'luxury flat in mohali',
                'property-listing',
                'property-testing',
                'active',
                '1000',
                '2.50',
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
            'keyword',
            'post_type',
            'listing',
            'status',
            'avg_search_volume',
            'avg_ranking',
        ];
    }
}