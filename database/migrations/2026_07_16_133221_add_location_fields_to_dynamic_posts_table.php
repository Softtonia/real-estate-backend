<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dynamic_posts', function (Blueprint $table) {
            if (!Schema::hasColumn('dynamic_posts', 'country_id')) {
                $table->foreignId('country_id')
                    ->nullable()
                    ->after('listing_code')
                    ->constrained('countries')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('dynamic_posts', 'state_id')) {
                $table->foreignId('state_id')
                    ->nullable()
                    ->after('country_id')
                    ->constrained('states')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('dynamic_posts', 'city_id')) {
                $table->foreignId('city_id')
                    ->nullable()
                    ->after('state_id')
                    ->constrained('cities')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('dynamic_posts', 'area_locality')) {
                $table->string('area_locality')
                    ->nullable()
                    ->after('city_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dynamic_posts', function (Blueprint $table) {
            if (Schema::hasColumn('dynamic_posts', 'city_id')) {
                $table->dropConstrainedForeignId('city_id');
            }

            if (Schema::hasColumn('dynamic_posts', 'state_id')) {
                $table->dropConstrainedForeignId('state_id');
            }

            if (Schema::hasColumn('dynamic_posts', 'country_id')) {
                $table->dropConstrainedForeignId('country_id');
            }

            if (Schema::hasColumn('dynamic_posts', 'area_locality')) {
                $table->dropColumn('area_locality');
            }
        });
    }
};