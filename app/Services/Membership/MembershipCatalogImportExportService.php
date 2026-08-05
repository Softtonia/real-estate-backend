<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipCategory;
use App\Models\Membership\MembershipFeature;
use App\Models\Membership\MembershipPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MembershipCatalogImportExportService
{
    private const EXCLUDED_CSV_COLUMNS = [
        'id',
        'slug',
        'sort_order',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function export(string $type, array $filters = []): StreamedResponse
    {
        $config = $this->config($type);
        $columns = $config['export_columns'];

        $filename = 'membership-' . $type . '-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($type, $filters, $columns) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $columns);

            if ($type === 'categories') {
                $this->exportCategories($handle, $filters, $columns);
            }

            if ($type === 'features') {
                $this->exportFeatures($handle, $filters, $columns);
            }

            if ($type === 'plans') {
                $this->exportPlans($handle, $filters, $columns);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function import(string $type, UploadedFile $file, string $mode = 'upsert'): array
    {
        $rows = $this->readImportFile($file);

        $result = [
            'total_rows' => count($rows),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;

            try {
                $row = $this->cleanCsvRow($this->normalizeRow($row));

                $data = match ($type) {
                    'categories' => $this->prepareCategoryImportData($row),
                    'features' => $this->prepareFeatureImportData($row),
                    'plans' => $this->preparePlanImportData($row),
                    default => throw new InvalidArgumentException('Invalid catalog type.'),
                };

                $validator = Validator::make($data, $this->rulesFor($type));

                if ($validator->fails()) {
                    $result['failed']++;
                    $result['errors'][] = [
                        'row' => $rowNumber,
                        'errors' => $validator->errors()->toArray(),
                    ];
                    continue;
                }

                $record = match ($type) {
                    'categories' => $this->saveCategory($data, $mode, $result),
                    'features' => $this->saveFeature($data, $mode, $result),
                    'plans' => $this->savePlan($data, $mode, $result),
                    default => throw new InvalidArgumentException('Invalid catalog type.'),
                };

                if (! $record) {
                    continue;
                }
            } catch (\Throwable $e) {
                $result['failed']++;

                if (count($result['errors']) < 50) {
                    $result['errors'][] = [
                        'row' => $rowNumber,
                        'errors' => [$e->getMessage()],
                    ];
                }
            }
        }

        return $result;
    }

    public function bulkDelete(string $type, array $ids): array
    {
        return DB::transaction(function () use ($type, $ids) {
            return match ($type) {
                'categories' => $this->bulkDeleteCategories($ids),
                'features' => $this->bulkDeleteFeatures($ids),
                'plans' => $this->bulkDeletePlans($ids),
                default => throw new InvalidArgumentException('Invalid catalog type.'),
            };
        });
    }

    private function exportCategories($handle, array $filters, array $columns): void
    {
        $query = MembershipCategory::query()
            ->select($this->availableColumns('membership_categories', [
                'name',
                'description',
                'status',
            ]));

        $this->applyExportFilters($query, 'categories', $filters);

        $query->orderBy('name')
            ->cursor()
            ->each(function ($record) use ($handle, $columns) {
                fputcsv($handle, [
                    $record->name,
                    $record->description,
                    $this->exportValue($record->status),
                ]);
            });
    }

    private function exportFeatures($handle, array $filters, array $columns): void
    {
        $query = MembershipFeature::query()
            ->select($this->availableColumns('membership_features', [
                'name',
                'description',
                'feature_type',
                'status',
            ]));

        $this->applyExportFilters($query, 'features', $filters);

        $query->orderBy('name')
            ->cursor()
            ->each(function ($record) use ($handle, $columns) {
                fputcsv($handle, [
                    $record->name,
                    $record->description,
                    $record->feature_type,
                    $this->exportValue($record->status),
                ]);
            });
    }

    private function exportPlans($handle, array $filters, array $columns): void
    {
        $categoryColumn = $this->planCategoryColumn();

        $query = MembershipPlan::query()
            ->select($this->availableColumns('membership_plans', [
                $categoryColumn,
                'name',
                'short_description',
                'description',
                'currency',
                'price',
                'sale_price',
                'duration',
                'duration_days',
                'duration_type',
                'trial_days',
                'is_popular',
                'status',
            ]))
            ->with('category:id,name,slug');

        $this->applyExportFilters($query, 'plans', $filters);

        $query->orderBy('name')
            ->cursor()
            ->each(function ($record) use ($handle) {
                fputcsv($handle, [
                    $record->category?->name,
                    $record->name,
                    $record->short_description,
                    $record->description,
                    $record->currency,
                    $record->price,
                    $record->sale_price,
                    $record->duration ?? $record->duration_days,
                    $record->duration_type,
                    $record->trial_days,
                    $this->exportValue($record->is_popular),
                    $this->exportValue($record->status),
                ]);
            });
    }

    private function saveCategory(array $data, string $mode, array &$result): ?MembershipCategory
    {
        $existing = MembershipCategory::query()
            ->where('slug', Str::slug($data['name']))
            ->orWhere('name', $data['name'])
            ->first();

        if ($mode === 'skip' && $existing) {
            $result['skipped']++;
            return null;
        }

        if ($existing) {
            $existing->update($data);
            $result['updated']++;
            return $existing;
        }

        $data['slug'] = $this->uniqueSlug($data['name'], MembershipCategory::class);
        $data['sort_order'] = $this->nextSortOrder('membership_categories');

        $record = MembershipCategory::query()->create($data);
        $result['created']++;

        return $record;
    }

    private function saveFeature(array $data, string $mode, array &$result): ?MembershipFeature
    {
        $existing = MembershipFeature::query()
            ->where('slug', Str::slug($data['name']))
            ->orWhere('name', $data['name'])
            ->first();

        if ($mode === 'skip' && $existing) {
            $result['skipped']++;
            return null;
        }

        if ($existing) {
            $existing->update($data);
            $result['updated']++;
            return $existing;
        }

        $data['slug'] = $this->uniqueSlug($data['name'], MembershipFeature::class);
        $data['sort_order'] = $this->nextSortOrder('membership_features');

        $record = MembershipFeature::query()->create($data);
        $result['created']++;

        return $record;
    }

    private function savePlan(array $data, string $mode, array &$result): ?MembershipPlan
    {
        $categoryColumn = $this->planCategoryColumn();
        $categoryId = (int) $data[$categoryColumn];

        $existing = MembershipPlan::query()
            ->where($categoryColumn, $categoryId)
            ->where('name', $data['name'])
            ->first();

        if (! $existing) {
            $generatedSlug = Str::slug($data['name']);

            $existing = MembershipPlan::query()
                ->where('slug', $generatedSlug)
                ->first();
        }

        if ($mode === 'skip' && $existing) {
            $result['skipped']++;
            return null;
        }

        if ($existing) {
            $existing->update($data);
            $result['updated']++;
            return $existing;
        }

        $data['slug'] = $this->uniqueSlug($data['name'], MembershipPlan::class);
        $data['sort_order'] = $this->nextSortOrder('membership_plans');

        $record = MembershipPlan::query()->create($data);
        $result['created']++;

        return $record;
    }

    private function prepareCategoryImportData(array $row): array
    {
        return [
            'name' => trim((string) ($row['name'] ?? '')),
            'description' => $row['description'] ?? null,
            'status' => $this->toBoolean($row['status'] ?? true),
        ];
    }

    private function prepareFeatureImportData(array $row): array
    {
        return [
            'name' => trim((string) ($row['name'] ?? '')),
            'description' => $row['description'] ?? null,
            'feature_type' => $row['feature_type'] ?? 'boolean',
            'status' => $this->toBoolean($row['status'] ?? true),
        ];
    }

    private function preparePlanImportData(array $row): array
    {
        $categoryName = trim((string) ($row['category_name'] ?? $row['category'] ?? ''));

        $category = MembershipCategory::query()
            ->where('name', $categoryName)
            ->orWhere('slug', Str::slug($categoryName))
            ->first();

        if (! $category) {
            throw new InvalidArgumentException("Category not found: {$categoryName}");
        }

        $categoryColumn = $this->planCategoryColumn();

        $data = [
            $categoryColumn => $category->id,
            'name' => trim((string) ($row['name'] ?? '')),
            'short_description' => $row['short_description'] ?? null,
            'description' => $row['description'] ?? null,
            'currency' => strtoupper((string) ($row['currency'] ?? 'INR')),
            'price' => $this->toMoney($row['price'] ?? 0),
            'sale_price' => $this->nullableMoney($row['sale_price'] ?? null),
            'trial_days' => (int) ($row['trial_days'] ?? 0),
            'is_popular' => $this->toBoolean($row['is_popular'] ?? false),
            'status' => $this->toBoolean($row['status'] ?? true),
        ];

        if (Schema::hasColumn('membership_plans', 'duration')) {
            $data['duration'] = (int) ($row['duration'] ?? $row['duration_days'] ?? 1);
        }

        if (Schema::hasColumn('membership_plans', 'duration_days')) {
            $data['duration_days'] = (int) ($row['duration'] ?? $row['duration_days'] ?? 1);
        }

        if (Schema::hasColumn('membership_plans', 'duration_type')) {
            $data['duration_type'] = $row['duration_type'] ?? 'days';
        }

        return $data;
    }

    private function bulkDeleteCategories(array $ids): array
    {
        $records = MembershipCategory::query()
            ->withCount('plans')
            ->whereIn('id', $ids)
            ->get();

        $deleted = 0;
        $deactivated = 0;

        foreach ($records as $category) {
            if ($category->plans_count > 0) {
                $category->update(['status' => false]);
                $deactivated++;
                continue;
            }

            $category->delete();
            $deleted++;
        }

        return [
            'requested' => count($ids),
            'found' => $records->count(),
            'deleted' => $deleted,
            'deactivated' => $deactivated,
            'missing' => count($ids) - $records->count(),
        ];
    }

    private function bulkDeleteFeatures(array $ids): array
    {
        $records = MembershipFeature::query()
            ->withCount('planFeatures')
            ->whereIn('id', $ids)
            ->get();

        $deleted = 0;
        $deactivated = 0;

        foreach ($records as $feature) {
            if ($feature->plan_features_count > 0) {
                $feature->update(['status' => false]);
                $deactivated++;
                continue;
            }

            $feature->delete();
            $deleted++;
        }

        return [
            'requested' => count($ids),
            'found' => $records->count(),
            'deleted' => $deleted,
            'deactivated' => $deactivated,
            'missing' => count($ids) - $records->count(),
        ];
    }

    private function bulkDeletePlans(array $ids): array
    {
        $records = MembershipPlan::query()
            ->whereIn('id', $ids)
            ->get();

        $deleted = 0;
        $deactivated = 0;

        foreach ($records as $plan) {
            if ($this->planHasUsage((int) $plan->id)) {
                $plan->update(['status' => false]);
                $deactivated++;
                continue;
            }

            $plan->delete();
            $deleted++;
        }

        return [
            'requested' => count($ids),
            'found' => $records->count(),
            'deleted' => $deleted,
            'deactivated' => $deactivated,
            'missing' => count($ids) - $records->count(),
        ];
    }

    private function planHasUsage(int $planId): bool
    {
        if (
            Schema::hasTable('membership_orders')
            && Schema::hasColumn('membership_orders', 'plan_id')
            && DB::table('membership_orders')->where('plan_id', $planId)->exists()
        ) {
            return true;
        }

        if (
            Schema::hasTable('user_memberships')
            && Schema::hasColumn('user_memberships', 'plan_id')
            && DB::table('user_memberships')->where('plan_id', $planId)->exists()
        ) {
            return true;
        }

        return false;
    }

    private function applyExportFilters(Builder $query, string $type, array $filters): void
    {
        $query->when(
            array_key_exists('status', $filters)
            && $filters['status'] !== null
            && $filters['status'] !== '',
            function ($q) use ($filters) {
                $q->where('status', filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN));
            }
        );

        $query->when(! empty($filters['search']), function ($q) use ($filters) {
            $search = trim((string) $filters['search']);

            $q->where(function ($subQuery) use ($search) {
                $subQuery->where('name', 'like', "%{$search}%");

                if ($subQuery->getModel() && Schema::hasColumn($subQuery->getModel()->getTable(), 'description')) {
                    $subQuery->orWhere('description', 'like', "%{$search}%");
                }
            });
        });

        if ($type === 'features' && ! empty($filters['feature_type'])) {
            $query->where('feature_type', $filters['feature_type']);
        }

        if ($type === 'plans' && ! empty($filters['category_id'])) {
            $query->where($this->planCategoryColumn(), (int) $filters['category_id']);
        }
    }

    private function readImportFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'json') {
            $decoded = json_decode(file_get_contents($file->getRealPath()), true);

            if (isset($decoded['data']) && is_array($decoded['data'])) {
                return $decoded['data'];
            }

            return is_array($decoded) ? $decoded : [];
        }

        $handle = fopen($file->getRealPath(), 'r');

        if (! $handle) {
            return [];
        }

        $header = fgetcsv($handle);

        if (! $header) {
            fclose($handle);
            return [];
        }

        $header = array_map(fn ($value) => $this->normalizeKey((string) $value), $header);

        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            if ($this->isEmptyCsvLine($line)) {
                continue;
            }

            $rows[] = array_combine($header, array_pad($line, count($header), null));
        }

        fclose($handle);

        return $rows;
    }

    private function cleanCsvRow(array $row): array
    {
        foreach (self::EXCLUDED_CSV_COLUMNS as $column) {
            unset($row[$column]);
        }

        return $row;
    }

    private function normalizeRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalized[$this->normalizeKey((string) $key)] = is_string($value) ? trim($value) : $value;
        }

        return $normalized;
    }

    private function normalizeKey(string $key): string
    {
        return Str::snake(trim(strtolower($key)));
    }

    private function isEmptyCsvLine(array $line): bool
    {
        foreach ($line as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), [
            '1',
            'true',
            'yes',
            'active',
            'enabled',
            'on',
        ], true);
    }

    private function exportValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    private function toMoney(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function nullableMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    private function nextSortOrder(string $table): int
    {
        if (! Schema::hasColumn($table, 'sort_order')) {
            return 0;
        }

        return ((int) DB::table($table)->max('sort_order')) + 10;
    }

    private function uniqueSlug(string $name, string $modelClass): string
    {
        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            $baseSlug = 'item';
        }

        $slug = $baseSlug;
        $counter = 1;

        while ($modelClass::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function planCategoryColumn(): string
    {
        return Schema::hasColumn('membership_plans', 'membership_category_id')
            ? 'membership_category_id'
            : 'category_id';
    }

    private function rulesFor(string $type): array
    {
        return match ($type) {
            'categories' => [
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'status' => ['nullable', 'boolean'],
            ],
            'features' => [
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'feature_type' => ['required', 'string', 'in:boolean,number,text,limit'],
                'status' => ['nullable', 'boolean'],
            ],
            'plans' => [
                'category_name' => ['nullable', 'string', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'short_description' => ['nullable', 'string'],
                'description' => ['nullable', 'string'],
                'currency' => ['nullable', 'string', 'size:3'],
                'price' => ['required', 'numeric', 'min:0'],
                'sale_price' => ['nullable', 'numeric', 'min:0'],
                'duration' => ['nullable', 'integer', 'min:1'],
                'duration_days' => ['nullable', 'integer', 'min:1'],
                'duration_type' => ['nullable', 'string', 'max:50'],
                'trial_days' => ['nullable', 'integer', 'min:0'],
                'is_popular' => ['nullable', 'boolean'],
                'status' => ['nullable', 'boolean'],
            ],
            default => [],
        };
    }

    private function availableColumns(string $table, array $columns): array
    {
        return array_values(array_filter($columns, fn ($column) => Schema::hasColumn($table, $column)));
    }

    private function config(string $type): array
    {
        return match ($type) {
            'categories' => [
                'model' => MembershipCategory::class,
                'table' => 'membership_categories',
                'export_columns' => [
                    'name',
                    'description',
                    'status',
                ],
                'import_columns' => [
                    'name',
                    'description',
                    'status',
                ],
            ],
            'features' => [
                'model' => MembershipFeature::class,
                'table' => 'membership_features',
                'export_columns' => [
                    'name',
                    'description',
                    'feature_type',
                    'status',
                ],
                'import_columns' => [
                    'name',
                    'description',
                    'feature_type',
                    'status',
                ],
            ],
            'plans' => [
                'model' => MembershipPlan::class,
                'table' => 'membership_plans',
                'export_columns' => [
                    'category_name',
                    'name',
                    'short_description',
                    'description',
                    'currency',
                    'price',
                    'sale_price',
                    'duration',
                    'duration_type',
                    'trial_days',
                    'is_popular',
                    'status',
                ],
                'import_columns' => [
                    'category_name',
                    'name',
                    'short_description',
                    'description',
                    'currency',
                    'price',
                    'sale_price',
                    'duration',
                    'duration_type',
                    'trial_days',
                    'is_popular',
                    'status',
                ],
            ],
            default => throw new InvalidArgumentException('Invalid catalog type.'),
        };
    }
}