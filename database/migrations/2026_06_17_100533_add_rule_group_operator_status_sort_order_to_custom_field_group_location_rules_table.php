<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('custom_field_group_location_rules', function (Blueprint $table) {
            if (!Schema::hasColumn('custom_field_group_location_rules', 'rule_group')) {
                $table->unsignedInteger('rule_group')->default(1)->after('custom_field_id');
            }

            if (!Schema::hasColumn('custom_field_group_location_rules', 'operator')) {
                $table->string('operator', 50)->default('is_equal_to')->after('show_if');
            }

            if (!Schema::hasColumn('custom_field_group_location_rules', 'status')) {
                $table->boolean('status')->default(true)->after('taxonomy_term_ids');
            }

            if (!Schema::hasColumn('custom_field_group_location_rules', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_field_group_location_rules', function (Blueprint $table) {
            $columns = ['rule_group', 'operator', 'status', 'sort_order'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('custom_field_group_location_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};