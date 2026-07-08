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
            'preview_rows' => $rows,
            'detected_mapping' => $this->detectMapping($headers),
        ];
    }

    public function validateMapping(array $mapping): array
    {
        $errors = [];

        foreach (['slug', 'keyword_type', 'post_type', 'keyword_list'] as $field) {
            if (empty($mapping[$field])) {
                $errors[$field][] = "{$field} column is required.";
            }
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
        $slugSeen = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $totalRows++;

            $prepared = $this->prepareRow($row, $headerMap, $mapping);

            $slug = $prepared['data']['slug'] ?? null;

            if ($slug && isset($slugSeen[$slug])) {
                $prepared['valid'] = false;
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

    public function prepareRow(array $row, array $headerMap, array $mapping): array
    {
        $errors = [];

        $id = $this->cell($row, $headerMap, $mapping['id'] ?? null);
        $slugInput = $this->cell($row, $headerMap, $mapping['slug'] ?? null);
        $keywordTypeInput = $this->cell($row, $headerMap, $mapping['keyword_type'] ?? null);
        $postTypeInput = $this->cell($row, $headerMap, $mapping['post_type'] ?? null);
        $keywordListInput = $this->cell($row, $headerMap, $mapping['keyword_list'] ?? null);

        $existingKeyword = null;

        if ($id) {
            $existingKeyword = Keyword::find((int) $id);
        }

        $slug = Str::slug((string) ($slugInput ?: $existingKeyword?->slug));

        if ($slug === '') {
            $errors[] = [
                'field' => 'slug',
                'message' => 'Slug is required.',
            ];
        }

        if (! $existingKeyword && $slug !== '') {
            $existingKeyword = Keyword::where('slug', $slug)->first();
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

        $keywordType = $this->resolveKeywordType($keywordTypeInput ?: $existingKeyword?->keyword_type);

        if (! $keywordType) {
            $errors[] = [
                'field' => 'keyword_type',
                'message' => 'Invalid keyword_type. Use post_types id, slug, or name.',
            ];
        }

        $listing = null;

        if ($keywordType) {
            $listing = $this->resolveListing(
                keywordTypeId: $keywordType->id,
                value: $postTypeInput ?: $existingKeyword?->post_type
            );
        }

        if (! $listing) {
            $errors[] = [
                'field' => 'post_type',
                'message' => 'Invalid post_type. Use dynamic_posts id, slug, or title belonging to selected keyword_type.',
            ];
        }

        $keywordList = Keyword::normalizeKeywords($keywordListInput);

        if (empty($keywordList)) {
            $errors[] = [
                'field' => 'keyword_list',
                'message' => 'keyword_list is required.',
            ];
        }

        return [
            'valid' => empty($errors),
            'existing_id' => $existingKeyword?->id,
            'errors' => $errors,
            'data' => [
                'slug' => $slug,
                'keyword_type' => $keywordType?->id,
                'post_type' => $listing?->id,
                'keyword_list' => $keywordList,
                'keyword_list_text' => implode(', ', $keywordList),
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

            if (! $keyword) {
                $keyword = Keyword::where('slug', $data['slug'])->first();
            }

            $payload = [
                'slug' => $data['slug'],
                'keyword_type' => $data['keyword_type'],
                'post_type' => $data['post_type'],
                'keyword_list' => $data['keyword_list'],
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
            'slug' => ['slug', 'keyword_slug'],
            'keyword_type' => ['keyword_type', 'type', 'post_type_type'],
            'post_type' => ['post_type', 'listing', 'listing_id', 'dynamic_post', 'dynamic_post_id'],
            'keyword_list' => ['keyword_list', 'keywords', 'keyword'],
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

    private function resolveKeywordType(mixed $value): ?PostType
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return PostType::where('id', (int) $value)->first();
        }

        return PostType::where('slug', $value)
            ->orWhere('name', $value)
            ->first();
    }

    private function resolveListing(int $keywordTypeId, mixed $value): ?DynamicPost
    {
        if ($value === null || $value === '') {
            return null;
        }

        $query = DynamicPost::query()
            ->where('post_type_id', $keywordTypeId);

        if (is_numeric($value)) {
            return $query->where('id', (int) $value)->first();
        }

        return $query->where(function ($q) use ($value) {
            $q->where('slug', $value)
                ->orWhere('title', $value);
        })->first();
    }
}