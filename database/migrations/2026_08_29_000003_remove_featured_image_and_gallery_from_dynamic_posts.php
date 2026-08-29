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
        if (Schema::hasTable('dynamic_posts')) {
            Schema::table('dynamic_posts', function (Blueprint $table) {
                if (Schema::hasColumn('dynamic_posts', 'featured_image_id')) {
                    $table->dropColumn('featured_image_id');
                }
                if (Schema::hasColumn('dynamic_posts', 'gallery_image_ids')) {
                    $table->dropColumn('gallery_image_ids');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('dynamic_posts')) {
            Schema::table('dynamic_posts', function (Blueprint $table) {
                if (!Schema::hasColumn('dynamic_posts', 'featured_image_id')) {
                    $table->unsignedBigInteger('featured_image_id')->nullable()->after('content');
                }
                if (!Schema::hasColumn('dynamic_posts', 'gallery_image_ids')) {
                    $table->json('gallery_image_ids')->nullable()->after('featured_image_id');
                }
            });
        }
    }
};
