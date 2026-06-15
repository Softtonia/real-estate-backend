<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxonomies', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('taxonomies')
                ->nullOnDelete();

            $table->boolean('is_relationship')
                ->default(false)
                ->after('parent_id')
                ->index('taxonomies_is_relationship_index');

            $table->boolean('is_parent')
                ->default(false)
                ->after('is_relationship')
                ->index('taxonomies_is_parent_index');

            $table->index(
                ['parent_id', 'is_relationship', 'is_parent'],
                'taxonomies_hierarchy_index'
            );

            $table->index(
                ['parent_id', 'status', 'sort_order'],
                'taxonomies_parent_status_sort_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('taxonomies', function (Blueprint $table) {
            $table->dropIndex('taxonomies_parent_status_sort_index');
            $table->dropIndex('taxonomies_hierarchy_index');
            $table->dropIndex('taxonomies_is_parent_index');
            $table->dropIndex('taxonomies_is_relationship_index');

            $table->dropForeign(['parent_id']);
            $table->dropColumn([
                'parent_id',
                'is_relationship',
                'is_parent',
            ]);
        });
    }
};