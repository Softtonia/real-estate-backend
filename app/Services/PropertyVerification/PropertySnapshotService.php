<?php

namespace App\Services\PropertyVerification;

use App\Models\DynamicPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PropertySnapshotService
{
    public function capture(DynamicPost $property): array
    {
        $property->refresh();
        $propertyId = (int) $property->id;

        return [
            'dynamic_post' => $this->cleanAttributes(
                $property->getAttributes(),
                ['id', 'created_at', 'updated_at']
            ),

            'post_taxonomy_terms' => $this->captureRows(
                'post_taxonomy_terms',
                fn ($query) => $query->where('dynamic_post_id', $propertyId)
            ),

            'custom_field_values' => $this->captureRows(
                'custom_field_values',
                fn ($query) => $query
                    ->where('entity_type', 'post')
                    ->where('entity_id', $propertyId)
            ),

            'custom_field_repeater_values' => $this->captureRows(
                'custom_field_repeater_values',
                fn ($query) => $query
                    ->where('entity_type', 'post')
                    ->where('entity_id', $propertyId)
            ),

            'dynamic_post_relationships' => $this->captureRows(
                'dynamic_post_relationships',
                fn ($query) => $query->where('dynamic_post_id', $propertyId)
            ),

            'keyword_dynamic_post' => $this->captureRows(
                'keyword_dynamic_post',
                fn ($query) => $query->where('dynamic_post_id', $propertyId)
            ),

            'dynamic_post_user' => $this->captureRows(
                'dynamic_post_user',
                fn ($query) => $query->where('dynamic_post_id', $propertyId)
            ),
        ];
    }

    public function restore(DynamicPost $property, array $snapshot): DynamicPost
    {
        $propertyId = (int) $property->id;

        $postPayload = $snapshot['dynamic_post'] ?? [];

        if (is_array($postPayload) && !empty($postPayload)) {
            $allowedColumns = array_flip(
                Schema::getColumnListing('dynamic_posts')
            );

            $postPayload = array_intersect_key(
                $postPayload,
                $allowedColumns
            );

            unset(
                $postPayload['id'],
                $postPayload['created_at'],
                $postPayload['updated_at']
            );

            $property->forceFill($postPayload);
            $property->save();
        }

        $this->restoreRows(
            table: 'post_taxonomy_terms',
            snapshotRows: $snapshot['post_taxonomy_terms'] ?? [],
            delete: fn ($query) => $query->where('dynamic_post_id', $propertyId),
            overrides: ['dynamic_post_id' => $propertyId],
        );

        $this->restoreRows(
            table: 'custom_field_values',
            snapshotRows: $snapshot['custom_field_values'] ?? [],
            delete: fn ($query) => $query
                ->where('entity_type', 'post')
                ->where('entity_id', $propertyId),
            overrides: [
                'entity_type' => 'post',
                'entity_id' => $propertyId,
            ],
        );

        $this->restoreRows(
            table: 'custom_field_repeater_values',
            snapshotRows: $snapshot['custom_field_repeater_values'] ?? [],
            delete: fn ($query) => $query
                ->where('entity_type', 'post')
                ->where('entity_id', $propertyId),
            overrides: [
                'entity_type' => 'post',
                'entity_id' => $propertyId,
            ],
        );

        $this->restoreRows(
            table: 'dynamic_post_relationships',
            snapshotRows: $snapshot['dynamic_post_relationships'] ?? [],
            delete: fn ($query) => $query->where('dynamic_post_id', $propertyId),
            overrides: ['dynamic_post_id' => $propertyId],
        );

        $this->restoreRows(
            table: 'keyword_dynamic_post',
            snapshotRows: $snapshot['keyword_dynamic_post'] ?? [],
            delete: fn ($query) => $query->where('dynamic_post_id', $propertyId),
            overrides: ['dynamic_post_id' => $propertyId],
        );

        $this->restoreRows(
            table: 'dynamic_post_user',
            snapshotRows: $snapshot['dynamic_post_user'] ?? [],
            delete: fn ($query) => $query->where('dynamic_post_id', $propertyId),
            overrides: ['dynamic_post_id' => $propertyId],
        );

        return $property->fresh();
    }

    private function captureRows(string $table, callable $callback): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }

        $query = DB::table($table);
        $callback($query);

        return $query
            ->get()
            ->map(function ($row) {
                return $this->cleanAttributes(
                    (array) $row,
                    ['id']
                );
            })
            ->values()
            ->all();
    }

    private function restoreRows(
        string $table,
        array $snapshotRows,
        callable $delete,
        array $overrides
    ): void {
        if (!Schema::hasTable($table)) {
            return;
        }

        $query = DB::table($table);
        $delete($query);
        $query->delete();

        if (empty($snapshotRows)) {
            return;
        }

        $allowedColumns = array_flip(
            Schema::getColumnListing($table)
        );

        $rows = collect($snapshotRows)
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row) use ($allowedColumns, $overrides) {
                unset($row['id']);

                $row = array_merge($row, $overrides);

                return array_intersect_key($row, $allowedColumns);
            })
            ->filter()
            ->values()
            ->all();

        if (!empty($rows)) {
            DB::table($table)->insert($rows);
        }
    }

    private function cleanAttributes(
        array $attributes,
        array $excludedColumns
    ): array {
        foreach ($excludedColumns as $column) {
            unset($attributes[$column]);
        }

        return $attributes;
    }
}
