<?php

namespace App\Services;

use App\Models\Keyword;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class KeywordCsvImportService
{
    public function __construct(
        protected KeywordRelationResolver $relationResolver
    ) {}

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

        return array_map(fn($header) => trim((string) $header), $headers);
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

        if (empty($mapping['keyword'])) {
            $errors['keyword'][] = 'Keyword column is required.';
        }

        if (empty($mapping['post_type'])) {
            $errors['post_type'][] = 'post_type column is required.';
        }

        if (empty($mapping['listing'])) {
            $errors['listing'][] = 'listing column is required.';
        }

        return $errors;
    }

    public function validateUpload(string $fullPath, array $mapping): array
    {
        $headers = $this->readHeaders($fullPath);
        $headerMap = $this->mapHeaders($headers);

        $mappingErrors = $this->validateMapping($mapping);

        if (! empty($mappingErrors)) {
            return [
                'status' => false,
                'total_rows' => 0,
                'valid_rows' => 0,
                'invalid_rows' => 0,
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
        $naturalKeysSeen = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $totalRows++;

            $prepared = $this->prepareRow(
                row: $row,
                headerMap: $headerMap,
                mapping: $mapping
            );

            $naturalKey = $prepared['natural_key'] ?? null;

            if ($naturalKey && isset($naturalKeysSeen[$naturalKey])) {
                $prepared['valid'] = false;
                $prepared['errors'][] = [
                    'field' => 'keyword',
                    'message' => 'Duplicate keyword + post_type + listing inside CSV. Already used on row ' . $naturalKeysSeen[$naturalKey] . '.',
                ];
            }

            if ($naturalKey) {
                $naturalKeysSeen[$naturalKey] = $rowNumber;
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

    public function prepareRow(array $row, array $headerMap, array $mapping): array
    {
        $errors = [];

        $keywordInput = $this->cell($row, $headerMap, $mapping['keyword'] ?? null);
        $postTypeInput = $this->cell($row, $headerMap, $mapping['post_type'] ?? null);
        $listingInput = $this->cell($row, $headerMap, $mapping['listing'] ?? null);
        $statusInput = $this->cell($row, $headerMap, $mapping['status'] ?? null);
        $avgSearchVolumeInput = $this->cell($row, $headerMap, $mapping['avg_search_volume'] ?? null);
        $avgRankingInput = $this->cell($row, $headerMap, $mapping['avg_ranking'] ?? null);

        $keywordText = Keyword::normalizeKeyword($keywordInput);

        if ($keywordText === '') {
            $errors[] = [
                'field' => 'keyword',
                'message' => 'Keyword is required.',
            ];
        }

        try {
            $keywordType = $this->relationResolver->resolveKeywordType($postTypeInput);
        } catch (\Throwable $e) {
            $keywordType = null;

            $errors[] = [
                'field' => 'post_type',
                'message' => $e->getMessage(),
            ];
        }

        $postType = null;

        if ($keywordType) {
            try {
                $postType = $this->relationResolver->resolvePostType(
                    $listingInput,
                    (int) $keywordType->id
                );
            } catch (\Throwable $e) {
                $errors[] = [
                    'field' => 'listing',
                    'message' => $e->getMessage(),
                ];
            }
        }

        $status = $this->normalizeStatus($statusInput ?: 'active');

        if (! in_array($status, ['active', 'inactive'], true)) {
            $errors[] = [
                'field' => 'status',
                'message' => 'Status must be active or inactive.',
            ];
        }

        $avgSearchVolume = null;

        if ($avgSearchVolumeInput !== null && $avgSearchVolumeInput !== '') {
            if (! is_numeric($avgSearchVolumeInput)) {
                $errors[] = [
                    'field' => 'avg_search_volume',
                    'message' => 'avg_search_volume must be numeric.',
                ];
            } else {
                $avgSearchVolume = (int) $avgSearchVolumeInput;
            }
        }

        $avgRanking = null;

        if ($avgRankingInput !== null && $avgRankingInput !== '') {
            if (! is_numeric($avgRankingInput)) {
                $errors[] = [
                    'field' => 'avg_ranking',
                    'message' => 'avg_ranking must be numeric.',
                ];
            } else {
                $avgRanking = round((float) $avgRankingInput, 2);
            }
        }

        $existingKeyword = null;
        $naturalKey = null;

        if ($keywordType && $postType && $keywordText !== '') {
            $existingKeyword = $this->findExistingByNaturalKey(
                keyword: $keywordText,
                keywordTypeId: (int) $keywordType->id,
                postTypeId: (int) $postType->id
            );

            $naturalKey = mb_strtolower($keywordText) . '|' . $keywordType->id . '|' . $postType->id;
        }

        return [
            'valid' => empty($errors),
            'existing_id' => $existingKeyword?->id,
            'natural_key' => $naturalKey,
            'errors' => $errors,
            'data' => [
                'keyword' => $keywordText,
                'post_type' => $keywordType?->slug,
                'listing' => $postType?->slug,
                'keyword_type_id' => $keywordType?->id,
                'post_type_id' => $postType?->id,
                'status' => $status,
                'avg_search_volume' => $avgSearchVolume,
                'avg_ranking' => $avgRanking,
            ],
        ];
    }

    public function upsertPreparedRow(array $prepared): string
    {
        if (! ($prepared['valid'] ?? false)) {
            throw new RuntimeException($prepared['errors'][0]['message'] ?? 'Invalid row.');
        }

        $data = $prepared['data'];

        return DB::transaction(function () use ($prepared, $data) {
            $keyword = null;

            if (! empty($prepared['existing_id'])) {
                $keyword = Keyword::find((int) $prepared['existing_id']);
            }

            $payload = [
                'keyword' => $data['keyword'],
                'status' => $data['status'],
                'avg_search_volume' => $data['avg_search_volume'],
                'avg_ranking' => $data['avg_ranking'],
            ];

            if ($keyword) {
                $keyword->update($payload);
                $result = 'updated';
            } else {
                $keyword = Keyword::create($payload);
                $result = 'created';
            }

            $keyword->postTypes()->sync([(int) $data['keyword_type_id']]);
            $keyword->dynamicPosts()->sync([(int) $data['post_type_id']]);

            return $result;
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
            $normalizedToOriginal[$this->normalizeHeader((string) $header)] = $header;
        }

        $aliases = [
            'keyword' => ['keyword', 'keywords', 'keyword_text'],
            'post_type' => ['post_type', 'keyword_type', 'type'],
            'listing' => ['listing', 'post', 'dynamic_post', 'dynamic_post_slug', 'post_slug'],
            'status' => ['status', 'active_inactive', 'active_deactive'],
            'avg_search_volume' => ['avg_search_volume', 'search_volume', 'volume'],
            'avg_ranking' => ['avg_ranking', 'ranking', 'rank'],
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

    private function findExistingByNaturalKey(
        string $keyword,
        int $keywordTypeId,
        int $postTypeId
    ): ?Keyword {
        return Keyword::query()
            ->where('keyword', $keyword)
            ->whereHas('postTypes', fn($query) => $query->where('post_types.id', $keywordTypeId))
            ->whereHas('dynamicPosts', fn($query) => $query->where('dynamic_posts.id', $postTypeId))
            ->first();
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
        $header = strtolower(trim((string) $header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header);

        return trim((string) $header, '_');
    }

    private function normalizeStatus(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            '1', 'yes', 'true', 'enabled', 'enable', 'active' => 'active',
            '0', 'no', 'false', 'disabled', 'disable', 'inactive', 'deactive' => 'inactive',
            default => $value,
        };
    }
}
