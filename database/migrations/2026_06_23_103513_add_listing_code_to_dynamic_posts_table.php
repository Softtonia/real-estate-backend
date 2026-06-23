<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dynamic_posts', function (Blueprint $table) {
            if (!Schema::hasColumn('dynamic_posts', 'listing_code')) {
                $table->string('listing_code')
                    ->nullable()
                    ->unique()
                    ->after('post_type_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dynamic_posts', function (Blueprint $table) {
            if (Schema::hasColumn('dynamic_posts', 'listing_code')) {
                $table->dropUnique(['listing_code']);
                $table->dropColumn('listing_code');
            }
        });
    }
};