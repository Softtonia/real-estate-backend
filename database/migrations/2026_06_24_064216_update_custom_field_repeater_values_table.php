<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_field_repeater_values', function (Blueprint $table) {
            if (!Schema::hasColumn('custom_field_repeater_values', 'entity_type')) {
                $table->string('entity_type', 50)->nullable()->after('custom_repeater_value_id');
            }

            if (!Schema::hasColumn('custom_field_repeater_values', 'entity_id')) {
                $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
            }

            if (!Schema::hasColumn('custom_field_repeater_values', 'custom_field_id')) {
                $table->unsignedBigInteger('custom_field_id')->nullable()->after('entity_id');
            }

            if (!Schema::hasColumn('custom_field_repeater_values', 'custom_field_repeater_id')) {
                $table->unsignedBigInteger('custom_field_repeater_id')->nullable()->after('custom_field_id');
            }

            if (!Schema::hasColumn('custom_field_repeater_values', 'custom_field_repeater_options_id')) {
                $table->unsignedBigInteger('custom_field_repeater_options_id')->nullable()->after('custom_field_repeater_id');
            }

            if (!Schema::hasColumn('custom_field_repeater_values', 'field_type')) {
                $table->string('field_type', 100)->nullable()->after('custom_field_repeater_options_id');
            }

            if (!Schema::hasColumn('custom_field_repeater_values', 'field_name_slug')) {
                $table->string('field_name_slug', 255)->nullable()->after('field_type');
            }

            if (!Schema::hasColumn('custom_field_repeater_values', 'field_label')) {
                $table->string('field_label', 255)->nullable()->after('field_name_slug');
            }

            if (!Schema::hasColumn('custom_field_repeater_values', 'field_meta_value')) {
                $table->longText('field_meta_value')->nullable()->after('field_label');
            }

            if (!Schema::hasColumn('custom_field_repeater_values', 'unique_id')) {
                $table->string('unique_id', 255)->nullable()->after('field_meta_value');
            }

            if (!Schema::hasColumn('custom_field_repeater_values', 'row_index')) {
                $table->unsignedInteger('row_index')->default(0)->after('unique_id');
            }

            if (!Schema::hasColumn('custom_field_repeater_values', 'repeater_index')) {
                $table->unsignedInteger('repeater_index')->default(0)->after('row_index');
            }

            if (!Schema::hasColumn('custom_field_repeater_values', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('repeater_index');
            }
        });

        $this->addIndexIfNotExists('custom_field_repeater_values', 'cfrv_entity_index', ['entity_type', 'entity_id']);
        $this->addIndexIfNotExists('custom_field_repeater_values', 'cfrv_custom_field_index', ['custom_field_id']);
        $this->addIndexIfNotExists('custom_field_repeater_values', 'cfrv_repeater_index', ['custom_field_repeater_id']);
        $this->addIndexIfNotExists('custom_field_repeater_values', 'cfrv_unique_index', ['unique_id']);
    }

    public function down(): void
    {
        Schema::table('custom_field_repeater_values', function (Blueprint $table) {
            $this->dropIndexIfExists('custom_field_repeater_values', 'cfrv_entity_index');
            $this->dropIndexIfExists('custom_field_repeater_values', 'cfrv_custom_field_index');
            $this->dropIndexIfExists('custom_field_repeater_values', 'cfrv_repeater_index');
            $this->dropIndexIfExists('custom_field_repeater_values', 'cfrv_unique_index');

            $columns = [
                'sort_order',
                'repeater_index',
                'row_index',
                'field_label',
                'field_name_slug',
                'entity_id',
                'entity_type',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('custom_field_repeater_values', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addIndexIfNotExists(string $table, string $indexName, array $columns): void
    {
        $database = DB::getDatabaseName();

        $exists = DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();

        if (!$exists) {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($columns, $indexName) {
                $tableBlueprint->index($columns, $indexName);
            });
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        $database = DB::getDatabaseName();

        $exists = DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();

        if ($exists) {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($indexName) {
                $tableBlueprint->dropIndex($indexName);
            });
        }
    }
};