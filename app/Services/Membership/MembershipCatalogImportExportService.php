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
    public function export(string $type, array $filters = []): StreamedResponse
    {
        $config = $this->config($type);
        $columns = $this->availableColumns($config['table'], $config['export_columns']);
        $model = $config['model'];

        $filename = 'membership-' . $type . '-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($model, $type, $filters, $columns) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $columns);

            /** @var Builder $query */
            $query = $model::query()->select($columns);

            $this->applyExportFilters($query, $type, $filters);

            $query->orderBy('id')
                ->cursor()
                ->each(function ($record) use ($handle, $columns) {
                    $row = [];

                    foreach ($columns as $column) {
                        $row[] = $this->exportValue($record->{$column});
                    }

                    fputcsv($handle, $row);
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function import(string $type, UploadedFile $file, string $mode = 'upsert'): array
    {
        $config = $this->config($type);
        $rows = $this->readImportFile($file);
        $columns = $this->availableColumns($config['table'], $config['import_columns']);
        $model = $config['model'];

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
                $data = $this->prepareImportData($type, $row, $columns);

                $validator = Validator::make($data, $this->rulesFor($type));

                if ($validator->fails()) {
                    $result['failed']++;
                    $result['errors'][] = [
                        'row' => $rowNumber,
                        'errors' => $validator->errors()->toArray(),
                    ];
                    continue;
                }

                $existing = $model::query()
                    ->where('slug', $data['slug'])
                    ->first();

                if ($mode === 'skip' && $existing) {
                    $result['skipped']++;
                    continue;
                }

                $record = $model::query()->updateOrCreate(
                    ['slug' => $data['slug']],
                    $data
                );

                if ($record->wasRecentlyCreated) {
                    $result['created']++;
                } else {
                    $result['updated']++;
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
        $query->when(isset($filters['status']) && $filters['status'] !== '', function ($q) use ($filters) {
            $q->where('status', filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN));
        });

        $query->when(!empty($filters['search']), function ($q) use ($filters) {
            $search = trim((string) $filters['search']);

            $q->where(function ($subQuery) use ($search) {
                $subQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        });

        if ($type === 'features' && !empty($filters['feature_type'])) {
            $query->where('feature_type', $filters['feature_type']);
        }

        if ($type === 'plans' && !empty($filters['category_id']) && Schema::hasColumn('membership_plans', 'category_id')) {
            $query->where('category_id', $filters['category_id']);
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

        if (!$handle) {
            return [];
        }

        $header = fgetcsv($handle);

        if (!$header) {
            fclose($handle);
            return [];
        }

        $header = array_map(fn ($value) => $this->normalizeKey($value), $header);

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

    private function prepareImportData(string $type, array $row, array $columns): array
    {
        $row = $this->normalizeRow($row);

        $name = trim((string) ($row['name'] ?? ''));
        $slug = trim((string) ($row['slug'] ?? ''));

        if ($slug === '' && $name !== '') {
            $slug = Str::slug($name);
        } else {
            $slug = Str::slug($slug);
        }

        $row['slug'] = $slug;

        $allowed = array_flip($columns);

        $data = array_intersect_key($row, $allowed);

        unset($data['id'], $data['created_at'], $data['updated_at'], $data['deleted_at']);

        if (array_key_exists('status', $allowed)) {
            $data['status'] = $this->toBoolean($data['status'] ?? true);
        }

        if (array_key_exists('sort_order', $allowed)) {
            $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        }

        if ($type === 'features' && array_key_exists('feature_type', $allowed)) {
            $data['feature_type'] = $data['feature_type'] ?? 'boolean';
        }

        if ($type === 'plans') {
            if (array_key_exists('currency', $allowed)) {
                $data['currency'] = $data['currency'] ?? 'INR';
            }

            foreach (['price', 'sale_price', 'discount_price', 'amount', 'final_amount'] as $moneyColumn) {
                if (array_key_exists($moneyColumn, $data) && $data[$moneyColumn] !== null && $data[$moneyColumn] !== '') {
                    $data[$moneyColumn] = (float) $data[$moneyColumn];
                }
            }

            foreach (['category_id', 'duration_days', 'sort_order', 'trial_days'] as $integerColumn) {
                if (array_key_exists($integerColumn, $data) && $data[$integerColumn] !== null && $data[$integerColumn] !== '') {
                    $data[$integerColumn] = (int) $data[$integerColumn];
                }
            }
        }

        return $data;
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

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'active', 'enabled'], true);
    }

    private function exportValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    private function rulesFor(string $type): array
    {
        return match ($type) {
            'categories' => [
                'name' => ['required', 'string', 'max:255'],
                'slug' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'status' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
            ],
            'features' => [
                'name' => ['required', 'string', 'max:255'],
                'slug' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'feature_type' => ['required', 'string', 'max:100'],
                'status' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
            ],
            'plans' => [
                'name' => ['required', 'string', 'max:255'],
                'slug' => ['required', 'string', 'max:255'],
                'status' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
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
                'export_columns' => ['id', 'name', 'slug', 'description', 'status', 'sort_order', 'created_at', 'updated_at'],
                'import_columns' => ['name', 'slug', 'description', 'status', 'sort_order'],
            ],
            'features' => [
                'model' => MembershipFeature::class,
                'table' => 'membership_features',
                'export_columns' => ['id', 'name', 'slug', 'description', 'feature_type', 'status', 'sort_order', 'created_at', 'updated_at'],
                'import_columns' => ['name', 'slug', 'description', 'feature_type', 'status', 'sort_order'],
            ],
            'plans' => [
                'model' => MembershipPlan::class,
                'table' => 'membership_plans',
                'export_columns' => [
                    'id',
                    'membership_category_id',
                    'category_id',
                    'name',
                    'slug',
                    'description',
                    'price',
                    'sale_price',
                    'currency',
                    'duration_days',
                    'trial_days',
                    'status',
                    'is_popular',
                    'sort_order',
                    'created_at',
                    'updated_at',
                ],
                'import_columns' => [
                    'membership_category_id',
                    'category_id',
                    'name',
                    'slug',
                    'description',
                    'price',
                    'sale_price',
                    'currency',
                    'duration_days',
                    'trial_days',
                    'status',
                    'is_popular',
                    'sort_order',
                ],
            ],
            default => throw new InvalidArgumentException('Invalid catalog type.'),
        };
    }
}