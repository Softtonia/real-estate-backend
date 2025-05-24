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
        Schema::table('custom_field_repeaters', function (Blueprint $table) {
            // Remove the old field_name column
            $table->dropColumn('field_name');

            // Add the new field_name_slug column with a unique constraint
            $table->string('field_name_slug')->unique()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_field_repeaters', function (Blueprint $table) {
            // Rollback: Remove field_name_slug and restore field_name
            $table->dropColumn('field_name_slug');
            $table->string('field_name')->after('id');
        });
    }
};
