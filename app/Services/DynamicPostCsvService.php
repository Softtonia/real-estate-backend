<?php

namespace App\Services;

use App\Models\DynamicPost;
use App\Models\Keyword;
use App\Models\PostType;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DynamicPostCsvService
{
    private array $headers = [
        'id',
        'post_type',
        'post_type_id',
        'listing_code',
        'title',
        'slug',
        'excerpt',
        'content',
        'status',
        'live_status',
        'parent_id',
        'sort_order',
        'published_at',
        'taxonomy_term_ids',
        'keywords',
    ];

    public function downloadTemplate(): StreamedResponse
    {
        $fileName = 'dynamic-posts-template.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $this->headers);

            fputcsv($handle, [
                '',
                'property-listing',
                '',
                '',
                'Luxury Flat in Mohali',
                'luxury-flat-in-mohali',
                'Premium 3 BHK flat available in Mohali.',
                'This is a luxury flat with modern amenities.',
                'draft',
                'submit',
                '',
                '0',
                '',
                '1,2,3',
                'luxury flat in mohali|ready to move flat|id:5',
            ]);

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $fileName = 'dynamic-posts-' . now()->format('Y-m-d-H-i-s') . '.csv';

        return response()->streamDownload(function () use ($request) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $this->headers);

            $query = DynamicPost::query()
                ->with(['postType', 'taxonomyTerms', 'keywords']);

            if ($request->filled('post_type_id')) {
                $query->where('post_type_id', (int) $request->post_type_id);
            }

            if ($request->filled('post_type')) {
                $postTypeValue = $request->post_type;

                $query->whereHas('postType', function ($q) use ($postTypeValue) {
                    $q->where('slug', $postTypeValue)
                        ->orWhere('name', $postTypeValue);
                });
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('live_status')) {
                $query->where('live_status', $request->live_status);
            }

            $query->orderBy('id')->chunk(300, function ($posts) use ($handle) {
                foreach ($posts as $post) {
                    fputcsv($handle, [
                        $post->id,
                        $post->postType?->slug,
                        $post->post_type_id,
                        $post->listing_code ?? '',
                        $post->title ?? '',
                        $post->slug ?? '',
                        $post->excerpt ?? '',
                        $post->content ?? '',
                        $post->status ?? '',
                        $post->live_status ?? '',
                        $post->parent_id ?? '',
                        $post->sort_order ?? '',
                        optional($post->published_at)->format('Y-m-d H:i:s'),
                        $post->taxonomyTerms
                            ->pluck('id')
                            ->implode(','),
                        $post->keywords
                            ->pluck('keyword')
                            ->implode('|'),
                    ]);
                }
            });

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function import(UploadedFile $file): array
    {
        $rows = $this->readCsv($file);

        $summary = [
            'total_rows' => count($rows),
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;

            try {
                DB::transaction(function () use ($row, &$summary) {
                    $result = $this->upsertDynamicPostFromRow($row);

                    if ($result === 'created') {
                        $summary['created']++;
                    }

                    if ($result === 'updated') {
                        $summary['updated']++;
                    }
                });
            } catch (Throwable $e) {
                $summary['failed']++;
                $summary['errors'][] = [
                    'row' => $rowNumber,
                    'message' => $e->getMessage(),
                    'data' => $row,
                ];
            }
        }

        return $summary;
    }

    private function readCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if (!$handle) {
            throw new RuntimeException('Unable to read uploaded CSV file.');
        }

        $headers = fgetcsv($handle);

        if (!$headers) {
            throw new RuntimeException('CSV header row is missing.');
        }

        $headers = array_map(fn($header) => $this->normalizeHeader($header), $headers);

        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if ($this->isEmptyRow($data)) {
                continue;
            }

            $row = [];

            foreach ($headers as $index => $header) {
                $row[$header] = $data[$index] ?? null;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function upsertDynamicPostFromRow(array $row): string
    {
        $postType = $this->resolvePostType(
            $row['post_type_id'] ?? null,
            $row['post_type'] ?? null
        );

        if (!$postType) {
            throw new RuntimeException('Invalid post_type or post_type_id.');
        }

        $title = trim((string) ($row['title'] ?? ''));

        if ($title === '') {
            throw new RuntimeException('Title is required.');
        }

        $existingPost = $this->findExistingDynamicPost($row, $postType);

        $slug = trim((string) ($row['slug'] ?? ''));

        if ($slug === '') {
            $slug = Str::slug($title);
        } else {
            $slug = Str::slug($slug);
        }

        $slug = $this->uniqueSlug($slug, (int) $postType->id, $existingPost?->id);

        $payload = $this->buildDynamicPostPayload($row, $postType, $slug);

        if ($existingPost) {
            $existingPost->update($payload);
            $post = $existingPost->fresh();
            $action = 'updated';
        } else {
            $payload['listing_code'] = $payload['listing_code']
                ?? $this->generateListingCode($postType);

            $post = DynamicPost::create($payload);
            $action = 'created';
        }

        $this->syncTaxonomyTerms($post, $row['taxonomy_term_ids'] ?? null);
        $this->syncKeywords($post, $postType, $row['keywords'] ?? null);

        return $action;
    }

    private function buildDynamicPostPayload(array $row, PostType $postType, string $slug): array
    {
        $payload = [
            'post_type_id' => (int) $postType->id,
            'title' => trim((string) ($row['title'] ?? '')),
            'slug' => $slug,
            'status' => $row['status'] ?: 'draft',
            'live_status' => $row['live_status'] ?: 'submit',
        ];

        $optionalColumns = [
            'listing_code',
            'excerpt',
            'content',
            'parent_id',
            'sort_order',
            'published_at',
        ];

        foreach ($optionalColumns as $column) {
            if (
                Schema::hasColumn('dynamic_posts', $column)
                && array_key_exists($column, $row)
                && $row[$column] !== null
                && $row[$column] !== ''
            ) {
                $payload[$column] = $row[$column];
            }
        }

        if (
            Schema::hasColumn('dynamic_posts', 'author_id')
            && empty($payload['author_id'])
            && Auth::id()
        ) {
            $payload['author_id'] = Auth::id();
        }

        if (
            ($payload['status'] ?? null) === 'published'
            && Schema::hasColumn('dynamic_posts', 'published_at')
            && empty($payload['published_at'])
        ) {
            $payload['published_at'] = now();
        }

        return $payload;
    }

    private function findExistingDynamicPost(array $row, PostType $postType): ?DynamicPost
    {
        if (!empty($row['id'])) {
            return DynamicPost::where('id', (int) $row['id'])->first();
        }

        if (!empty($row['listing_code']) && Schema::hasColumn('dynamic_posts', 'listing_code')) {
            $post = DynamicPost::where('post_type_id', $postType->id)
                ->where('listing_code', trim((string) $row['listing_code']))
                ->first();

            if ($post) {
                return $post;
            }
        }

        if (!empty($row['slug'])) {
            return DynamicPost::where('post_type_id', $postType->id)
                ->where('slug', Str::slug($row['slug']))
                ->first();
        }

        return null;
    }

    private function resolvePostType(mixed $postTypeId, mixed $postType): ?PostType
    {
        return PostType::query()
            ->where(function ($query) use ($postTypeId, $postType) {
                if (!empty($postTypeId) && is_numeric($postTypeId)) {
                    $query->where('id', (int) $postTypeId);
                }

                if (!empty($postType)) {
                    $query->orWhere('slug', trim((string) $postType))
                        ->orWhere('name', trim((string) $postType));
                }
            })
            ->first();
    }

    private function uniqueSlug(string $slug, int $postTypeId, ?int $ignoreId = null): string
    {
        $baseSlug = $slug ?: 'dynamic-post';
        $finalSlug = $baseSlug;
        $counter = 1;

        while (
            DynamicPost::where('post_type_id', $postTypeId)
                ->where('slug', $finalSlug)
                ->when($ignoreId, fn($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $finalSlug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $finalSlug;
    }

    private function syncTaxonomyTerms(DynamicPost $post, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $termIds = collect(explode(',', (string) $value))
            ->map(fn($id) => trim($id))
            ->filter(fn($id) => $id !== '' && is_numeric($id))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        $post->taxonomyTerms()->sync($termIds);
    }

    private function syncKeywords(DynamicPost $post, PostType $postType, mixed $value): void
    {
        if (
            $value === null
            || $value === ''
            || !Schema::hasTable('keywords')
            || !Schema::hasTable('keyword_post_type')
            || !Schema::hasTable('keyword_dynamic_post')
        ) {
            return;
        }

        $items = collect(preg_split('/[|,]/', (string) $value))
            ->map(fn($item) => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $keywordIds = [];

        foreach ($items as $item) {
            $keyword = null;

            if (str_starts_with($item, 'id:')) {
                $keywordId = (int) str_replace('id:', '', $item);
                $keyword = Keyword::find($keywordId);

                if (!$keyword) {
                    throw new RuntimeException('Invalid keyword id: ' . $keywordId);
                }
            } else {
                $keywordText = Keyword::normalizeKeyword($item);

                if ($keywordText === '') {
                    continue;
                }

                $keyword = $this->findExistingKeywordByNaturalKey(
                    $keywordText,
                    (int) $postType->id,
                    (int) $post->id
                );

                if (!$keyword) {
                    $keyword = Keyword::create([
                        'keyword' => $keywordText,
                        'status' => 'active',
                        'avg_search_volume' => null,
                        'avg_ranking' => null,
                    ]);
                }
            }

            $keyword->postTypes()->syncWithoutDetaching([
                (int) $postType->id,
            ]);

            $keyword->dynamicPosts()->syncWithoutDetaching([
                (int) $post->id,
            ]);

            $keywordIds[] = (int) $keyword->id;
        }

        DB::table('keyword_dynamic_post')
            ->where('dynamic_post_id', $post->id)
            ->when(!empty($keywordIds), fn($query) => $query->whereNotIn('keyword_id', $keywordIds))
            ->delete();
    }

    private function findExistingKeywordByNaturalKey(
        string $keyword,
        int $postTypeId,
        int $dynamicPostId
    ): ?Keyword {
        return Keyword::query()
            ->whereRaw('LOWER(keyword) = ?', [mb_strtolower($keyword)])
            ->whereHas('postTypes', fn($query) => $query->where('post_types.id', $postTypeId))
            ->whereHas('dynamicPosts', fn($query) => $query->where('dynamic_posts.id', $dynamicPostId))
            ->first();
    }

    private function generateListingCode(PostType $postType): string
    {
        if (!Schema::hasColumn('dynamic_posts', 'listing_code')) {
            return '';
        }

        $prefix = strtoupper(substr(Str::slug($postType->slug ?? $postType->name ?? 'DYN', ''), 0, 4)) ?: 'DYN';

        $lastCode = DynamicPost::where('post_type_id', $postType->id)
            ->whereNotNull('listing_code')
            ->where('listing_code', 'like', $prefix . '-%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('listing_code');

        $nextNumber = 1;

        if (!empty($lastCode) && preg_match('/-(\d+)$/', $lastCode, $matches)) {
            $nextNumber = ((int) $matches[1]) + 1;
        }

        return $prefix . '-' . str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
    }

    private function normalizeHeader(mixed $header): string
    {
        return Str::of((string) $header)
            ->trim()
            ->lower()
            ->replace(' ', '_')
            ->replace('-', '_')
            ->toString();
    }

    private function isEmptyRow(array $row): bool
    {
        return collect($row)
            ->filter(fn($value) => trim((string) $value) !== '')
            ->isEmpty();
    }
}