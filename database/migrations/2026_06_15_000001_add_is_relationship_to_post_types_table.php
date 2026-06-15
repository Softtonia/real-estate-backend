<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('post_types', function (Blueprint $table) {
            $table->boolean('is_relationship')
                ->default(false)
                ->after('is_default')
                ->comment('1 = this post type can have relationships with other post types, 0 = standalone post type');

            $table->index(['is_relationship', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('post_types', function (Blueprint $table) {
            $table->dropIndex(['is_relationship', 'status']);
            $table->dropColumn('is_relationship');
        });
    }
};