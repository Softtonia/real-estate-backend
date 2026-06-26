<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_taxonomy_terms', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('dynamic_post_id');

            $table->foreignId('taxonomy_id')
                ->constrained('taxonomies')
                ->cascadeOnDelete();

            $table->foreignId('taxonomy_term_id')
                ->constrained('taxonomy_terms')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(
                ['dynamic_post_id', 'taxonomy_term_id'],
                'unique_dynamic_post_taxonomy_term'
            );

            $table->index(
                ['dynamic_post_id', 'taxonomy_id'],
                'idx_dynamic_post_taxonomy'
            );

            $table->index(
                ['taxonomy_id', 'taxonomy_term_id', 'dynamic_post_id'],
                'idx_taxonomy_term_dynamic_posts'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_taxonomy_terms');
    }
};