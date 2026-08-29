<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('custom_fields')) {
            Schema::table('custom_fields', function (Blueprint $table) {
                if (!Schema::hasColumn('custom_fields', 'has_featured')) {
                    $table->boolean('has_featured')->default(false)->after('media_format');
                }
            });
        }

        if (Schema::hasTable('custom_field_repeaters')) {
            Schema::table('custom_field_repeaters', function (Blueprint $table) {
                if (!Schema::hasColumn('custom_field_repeaters', 'has_featured')) {
                    $table->boolean('has_featured')->default(false)->after('media_format');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('custom_fields')) {
            Schema::table('custom_fields', function (Blueprint $table) {
                if (Schema::hasColumn('custom_fields', 'has_featured')) {
                    $table->dropColumn('has_featured');
                }
            });
        }

        if (Schema::hasTable('custom_field_repeaters')) {
            Schema::table('custom_field_repeaters', function (Blueprint $table) {
                if (Schema::hasColumn('custom_field_repeaters', 'has_featured')) {
                    $table->dropColumn('has_featured');
                }
            });
        }
    }
};
