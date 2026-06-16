<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Change value_string from VARCHAR(255) to TEXT to support longer values
     * (email, URL, long string values from options, serialized checkbox IDs).
     *
     * Must drop the existing index first since TEXT columns require a key length.
     */
    public function up(): void
    {
        // Drop index that depends on value_string
        Schema::table('custom_field_values', function (Blueprint $table) {
            $table->dropIndex('idx_custom_field_values_string');
        });

        // Now change the column type
        Schema::table('custom_field_values', function (Blueprint $table) {
            $table->text('value_string')->nullable()->change();
        });

        // Re-create the index with a key prefix length (first 191 chars for utf8mb4)
        DB::statement('ALTER TABLE custom_field_values ADD INDEX idx_custom_field_values_string (custom_field_id, value_string(191))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the TEXT-based index
        Schema::table('custom_field_values', function (Blueprint $table) {
            $table->dropIndex('idx_custom_field_values_string');
        });

        // Revert to VARCHAR
        Schema::table('custom_field_values', function (Blueprint $table) {
            $table->string('value_string', 255)->nullable()->change();
        });

        // Re-create the original index
        Schema::table('custom_field_values', function (Blueprint $table) {
            $table->index(['custom_field_id', 'value_string'], 'idx_custom_field_values_string');
        });
    }
};