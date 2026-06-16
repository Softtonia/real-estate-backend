<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Check if column already exists (from a previous partial run)
        if (!Schema::hasColumn('custom_field_group_location_rules', 'custom_field_id')) {
            Schema::table('custom_field_group_location_rules', function (Blueprint $table) {
                $table->foreignId('custom_field_id')
                    ->nullable()
                    ->after('custom_field_group_id')
                    ->constrained('custom_fields')
                    ->cascadeOnDelete();
            });
        }

        // Add index if it doesn't exist
        try {
            Schema::table('custom_field_group_location_rules', function (Blueprint $table) {
                $table->index(['custom_field_group_id', 'custom_field_id'], 'cfglr_group_field_idx');
            });
        } catch (\Exception $e) {
            // Index likely already exists, that's fine
        }
    }

    public function down(): void
    {
        Schema::table('custom_field_group_location_rules', function (Blueprint $table) {
            $table->dropForeign(['custom_field_id']);
            $table->dropColumn('custom_field_id');
            $table->dropIndex('cfglr_group_field_idx');
        });
    }
};