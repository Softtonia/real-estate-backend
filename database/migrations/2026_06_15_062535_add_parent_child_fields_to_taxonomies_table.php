<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxonomies', function (Blueprint $table) {
            $table->boolean('is_relationship')
                ->default(false)
                ->after('id')
                ->index('taxonomies_is_relationship_index');

            $table->boolean('is_parent')
                ->default(false)
                ->after('is_relationship')
                ->index('taxonomies_is_parent_index');

            $table->index(
                ['is_relationship', 'is_parent', 'status', 'sort_order'],
                'taxonomies_relationship_parent_status_sort_index'
            );
        });

        Schema::create('taxonomy_relationships', function (Blueprint $table) {
            $table->id();

            $table->foreignId('parent_taxonomy_id')
                ->constrained('taxonomies')
                ->cascadeOnDelete();

            $table->foreignId('child_taxonomy_id')
                ->constrained('taxonomies')
                ->cascadeOnDelete();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);

            $table->timestamps();

            $table->unique(
                ['parent_taxonomy_id', 'child_taxonomy_id'],
                'taxonomy_parent_child_unique'
            );

            $table->index(
                ['parent_taxonomy_id', 'status', 'sort_order'],
                'taxonomy_parent_status_sort_index'
            );

            $table->index(
                ['child_taxonomy_id', 'status', 'sort_order'],
                'taxonomy_child_status_sort_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_relationships');

        Schema::table('taxonomies', function (Blueprint $table) {
            $table->dropIndex('taxonomies_relationship_parent_status_sort_index');
            $table->dropIndex('taxonomies_is_parent_index');
            $table->dropIndex('taxonomies_is_relationship_index');

            $table->dropColumn([
                'is_relationship',
                'is_parent',
            ]);
        });
    }
};