<?php

namespace App\Services;

use App\Models\DynamicPost;
use App\Models\Keyword;
use App\Models\PostType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class KeywordCsvImportService
{
    public function readHeaders(string $fullPath): array
    {
        $handle = fopen($fullPath, 'r');

        if (! $handle) {
            throw new RuntimeException('Unable to open CSV file.');
        }

        $headers = fgetcsv($handle);

        fclose($handle);

        if (! is_array($headers) || empty($headers)) {
            throw new RuntimeException('CSV header row missing.');
        }

        return array_map(fn ($header) => trim((string) $header), $headers);
    }

    public function readPreviewRows(string $fullPath, int $limit = 10): array
    {
        $headers = $this->readHeaders($fullPath);
        $headerMap = $this->mapHeaders($headers);

        $handle = fopen($fullPath, 'r');

        if (! $handle) {
            throw new RuntimeException('Unable to open CSV file.');
        }

        fgetcsv($handle);

        $rows = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $item = [
                'row_number' => $rowNumber,
            ];

            foreach ($headers as $index => $header) {
                $item[$header] = $row[$index] ?? null;
            }

            $rows[] = $item;

            if (count($rows) >= $limit) {
                break;
            }
        }

        fclose($handle);

        return [
            'headers' => $headers,
            'header_map' => $headerMap,
            'preview_rows' => $rows,
            'detected_mapping' => $this->detectMapping($headers),
        ];
    }

    public function validateMapping(array $mapping): array
    {
        $errors = [];

        if (empty($mapping['slug'])) {
            $errors['slug'][] = 'Slug column is required.';
        }

        if (empty($mapping['keyword_list']) && empty($mapping['keyword'])) {
            $errors['keyword_list'][] = 'Keyword column is required.';
        }

        return $errors;
    }

    public function validateUpload(
        string $fullPath,
        array $mapping,
        string $fileKey
    ): array {
        $headers = $this->readHeaders($fullPath);
        $headerMap = $this->mapHeaders($headers);

        $mappingErrors = $this->validateMapping($mapping);

        if (! empty($mappingErrors)) {
            return [
                'status' => false,
                'valid_rows' => 0,
                'invalid_rows' => 0,
                'total_rows' => 0,
                'errors' => $mappingErrors,
                'preview' => [],
            ];
        }

        $handle = fopen($fullPath, 'r');

        if (! $handle) {
            throw new RuntimeException('Unable to open CSV file.');
        }

        fgetcsv($handle);

        $rowNumber = 1;
        $totalRows = 0;
        $validRows = 0;
        $invalidRows = 0;
        $errors = [];
        $preview = [];
        $slugSeen = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $totalRows++;

            $prepared = $this->prepareRow(
                row: $row,
                headerMap: $headerMap,
                mapping: $mapping,
                fileKey: $fileKey,
                rowNumber: $rowNumber
            );

            $slug = $prepared['data']['slug'] ?? null;

            if ($slug && isset($slugSeen[$slug])) {
                $prepared['errors'][] = [
                    'field' => 'slug',
                    'message' => 'Duplicate slug inside CSV. Already used on row ' . $slugSeen[$slug] . '.',
                ];
            }

            if ($slug) {
                $slugSeen[$slug] = $rowNumber;
            }

            if ($prepared['valid']) {
                $validRows++;
            } else {
                $invalidRows++;

                foreach ($prepared['errors'] as $error) {
                    $errors[] = [
                        'row_number' => $rowNumber,
                        'field' => $error['field'] ?? null,
                        'message' => $error['message'] ?? 'Invalid row.',
                    ];

                    if (count($errors) >= 200) {
                        break 2;
                    }
                }
            }

            if (count($preview) < 20) {
                $preview[] = [
                    'row_number' => $rowNumber,
                    'valid' => $prepared['valid'],
                    'will_update' => $prepared['existing_id'] !== null,
                    'existing_id' => $prepared['existing_id'],
                    'data' => $prepared['data'],
                    'errors' => $prepared['errors'],
                ];
            }
        }

        fclose($handle);

        return [
            'status' => $invalidRows === 0,
            'total_rows' => $totalRows,
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
            'errors' => $errors,
            'preview' => $preview,
        ];
    }

    public function prepareRow(
        array $row,
        array $headerMap,
        array $mapping,
        string $fileKey,
        int $rowNumber
    ): array {
        $errors = [];

        $data = [
            'id' => $this->cell($row, $headerMap, $mapping['id'] ?? null),
            'import_uid' => $this->cell($row, $headerMap, $mapping['import_uid'] ?? null),

            'slug' => $this->cell($row, $headerMap, $mapping['slug'] ?? null),
            'keyword_type' => $this->cell($row, $headerMap, $mapping['keyword_type'] ?? null),

            'post_type_id' => $this->cell($row, $headerMap, $mapping['post_type_id'] ?? null),
            'post_type_slug' => $this->cell($row, $headerMap, $mapping['post_type_slug'] ?? null),

            'dynamic_post_id' => $this->cell($row, $headerMap, $mapping['dynamic_post_id'] ?? null),
            'dynamic_post_slug' => $this->cell($row, $headerMap, $mapping['dynamic_post_slug'] ?? null),

            'keyword_list' => $this->cell($row, $headerMap, $mapping['keyword_list'] ?? ($mapping['keyword'] ?? null)),

            'search_volume' => $this->cell($row, $headerMap, $mapping['search_volume'] ?? null),
            'ranking' => $this->cell($row, $headerMap, $mapping['ranking'] ?? null),
            'intent' => $this->cell($row, $headerMap, $mapping['intent'] ?? null),
        ];

        $existingKeyword = $this->findExistingKeyword($data, $fileKey, $rowNumber);

        $slug = Str::slug((string) ($data['slug'] ?: $existingKeyword?->slug));

        if ($slug === '') {
            $errors[] = [
                'field' => 'slug',
                'message' => 'Slug is required.',
            ];
        }

        if ($slug !== '') {
            $duplicateSlugExists = Keyword::query()
                ->where('slug', $slug)
                ->when($existingKeyword, fn ($q) => $q->where('id', '!=', $existingKeyword->id))
                ->exists();

            if ($duplicateSlugExists) {
                $errors[] = [
                    'field' => 'slug',
                    'message' => "Slug '{$slug}' already exists.",
                ];
            }
        }

        $keywordType = strtolower((string) (
            $data['keyword_type']
            ?: $existingKeyword?->keyword_type
            ?: 'post_type'
        ));

        if (! in_array($keywordType, ['post_type', 'dynamic_post'], true)) {
            $errors[] = [
                'field' => 'keyword_type',
                'message' => 'Keyword type must be post_type or dynamic_post.',
            ];
        }

        $postType = $this->resolvePostType($data, $existingKeyword);

        if (! $postType) {
            $errors[] = [
                'field' => 'post_type',
                'message' => 'Post type not found.',
            ];
        }

        $dynamicPost = null;

        if ($keywordType === 'dynamic_post') {
            if ($postType) {
                $dynamicPost = $this->resolveDynamicPost($data, $postType, $existingKeyword);
            }

            if (! $dynamicPost) {
                $errors[] = [
                    'field' => 'dynamic_post',
                    'message' => 'Dynamic post not found or does not belong to selected post type.',
                ];
            }
        }

        $keywordList = Keyword::normalizeKeywords($data['keyword_list']);

        if (empty($keywordList)) {
            $errors[] = [
                'field' => 'keyword_list',
                'message' => 'Keyword is required.',
            ];
        }

        $searchVolume = null;

        if ($data['search_volume'] !== null && $data['search_volume'] !== '') {
            if (! is_numeric($data['search_volume'])) {
                $errors[] = [
                    'field' => 'search_volume',
                    'message' => 'Search volume must be numeric.',
                ];
            } else {
                $searchVolume = (int) $data['search_volume'];
            }
        }

        $ranking = null;

        if ($data['ranking'] !== null && $data['ranking'] !== '') {
            if (! is_numeric($data['ranking'])) {
                $errors[] = [
                    'field' => 'ranking',
                    'message' => 'Ranking must be numeric.',
                ];
            } else {
                $ranking = (int) $data['ranking'];
            }
        }

        return [
            'valid' => empty($errors),
            'existing_id' => $existingKeyword?->id,
            'errors' => $errors,
            'data' => [
                'slug' => $slug,
                'keyword_type' => $keywordType,
                'post_type_id' => $postType?->id,
                'post_type_slug' => $postType?->slug,
                'dynamic_post_id' => $dynamicPost?->id,
                'dynamic_post_slug' => $dynamicPost?->slug,
                'keyword_list' => $keywordList,
                'keyword_list_text' => implode(', ', $keywordList),
                'search_volume' => $searchVolume,
                'ranking' => $ranking,
                'intent' => $data['intent'] ?: null,
                'import_uid' => $existingKeyword?->import_uid ?: ($data['import_uid'] ?: (string) Str::uuid()),
                'import_file_key' => $fileKey,
                'import_row_number' => $rowNumber,
            ],
        ];
    }

    public function upsertPreparedRow(array $prepared, string $batchId): string
    {
        if (! ($prepared['valid'] ?? false)) {
            throw new RuntimeException($prepared['errors'][0]['message'] ?? 'Invalid row.');
        }

        $data = $prepared['data'];

        return DB::transaction(function () use ($prepared, $data, $batchId) {
            $keyword = null;

            if (! empty($prepared['existing_id'])) {
                $keyword = Keyword::find((int) $prepared['existing_id']);
            }

            if (! $keyword) {
                $keyword = Keyword::where('import_file_key', $data['import_file_key'])
                    ->where('import_row_number', $data['import_row_number'])
                    ->first();
            }

            if (! $keyword) {
                $keyword = Keyword::where('slug', $data['slug'])->first();
            }

            $payload = [
                'slug' => $data['slug'],
                'keyword_type' => $data['keyword_type'],
                'post_type_id' => $data['post_type_id'],
                'dynamic_post_id' => $data['dynamic_post_id'],
                'keyword_list' => $data['keyword_list'],
                'search_volume' => $data['search_volume'],
                'ranking' => $data['ranking'],
                'intent' => $data['intent'],
                'import_uid' => $data['import_uid'],
                'import_file_key' => $data['import_file_key'],
                'import_row_number' => $data['import_row_number'],
                'last_import_batch_id' => $batchId,
            ];

            if ($keyword) {
                $keyword->update($payload);
                return 'updated';
            }

            Keyword::create($payload);

            return 'created';
        });
    }

    public function countDataRows(string $fullPath): int
    {
        $handle = fopen($fullPath, 'r');

        if (! $handle) {
            return 0;
        }

        $count = 0;
        $rowNumber = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($rowNumber === 1) {
                continue;
            }

            if (! $this->isEmptyRow($row)) {
                $count++;
            }
        }

        fclose($handle);

        return $count;
    }

    public function mapHeaders(array $headers): array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            $key = $this->normalizeHeader((string) $header);

            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = $index;
            }
        }

        return $map;
    }

    public function detectMapping(array $headers): array
    {
        $normalizedToOriginal = [];

        foreach ($headers as $header) {
            $normalizedToOriginal[$this->normalizeHeader($header)] = $header;
        }

        $aliases = [
            'id' => ['id'],
            'import_uid' => ['import_uid', 'uid'],
            'slug' => ['keyword_slug', 'slug', 'keyword_url_slug'],
            'keyword_list' => ['keyword', 'keywords', 'keyword_list', 'keyword_text'],
            'keyword_type' => ['keyword_type', 'type'],
            'post_type_id' => ['post_type_id'],
            'post_type_slug' => ['post_type_slug', 'post_type'],
            'dynamic_post_id' => ['dynamic_post_id', 'listing_id', 'post_id'],
            'dynamic_post_slug' => ['dynamic_post_slug', 'listing_slug', 'post_slug', 'listing'],
            'search_volume' => ['search_volume', 'volume'],
            'ranking' => ['ranking', 'rank'],
            'intent' => ['intent', 'search_intent'],
        ];

        $mapping = [];

        foreach ($aliases as $field => $fieldAliases) {
            foreach ($fieldAliases as $alias) {
                if (isset($normalizedToOriginal[$alias])) {
                    $mapping[$field] = $normalizedToOriginal[$alias];
                    break;
                }
            }
        }

        return $mapping;
    }

    public function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function cell(array $row, array $headerMap, ?string $mappedHeader): mixed
    {
        if (! $mappedHeader) {
            return null;
        }

        $key = $this->normalizeHeader($mappedHeader);

        if (! array_key_exists($key, $headerMap)) {
            return null;
        }

        $index = $headerMap[$key];

        $value = $row[$index] ?? null;

        return is_string($value) ? trim($value) : $value;
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header);

        return trim((string) $header, '_');
    }

    private function findExistingKeyword(array $data, string $fileKey, int $rowNumber): ?Keyword
    {
        if (! empty($data['id'])) {
            $keyword = Keyword::find((int) $data['id']);

            if ($keyword) {
                return $keyword;
            }
        }

        if (! empty($data['import_uid'])) {
            $keyword = Keyword::where('import_uid', $data['import_uid'])->first();

            if ($keyword) {
                return $keyword;
            }
        }

        $keyword = Keyword::where('import_file_key', $fileKey)
            ->where('import_row_number', $rowNumber)
            ->first();

        if ($keyword) {
            return $keyword;
        }

        if (! empty($data['slug'])) {
            return Keyword::where('slug', Str::slug((string) $data['slug']))->first();
        }

        return null;
    }

    private function resolvePostType(array $data, ?Keyword $keyword = null): ?PostType
    {
        if (! empty($data['post_type_id'])) {
            return PostType::find((int) $data['post_type_id']);
        }

        if (! empty($data['post_type_slug'])) {
            return PostType::where('slug', $data['post_type_slug'])->first();
        }

        if ($keyword?->post_type_id) {
            return PostType::find($keyword->post_type_id);
        }

        return null;
    }

    private function resolveDynamicPost(array $data, PostType $postType, ?Keyword $keyword = null): ?DynamicPost
    {
        if (! empty($data['dynamic_post_id'])) {
            return DynamicPost::where('id', (int) $data['dynamic_post_id'])
                ->where('post_type_id', $postType->id)
                ->first();
        }

        if (! empty($data['dynamic_post_slug'])) {
            return DynamicPost::where('slug', $data['dynamic_post_slug'])
                ->where('post_type_id', $postType->id)
                ->first();
        }

        if ($keyword?->dynamic_post_id) {
            return DynamicPost::where('id', $keyword->dynamic_post_id)
                ->where('post_type_id', $postType->id)
                ->first();
        }

        return null;
    }
}