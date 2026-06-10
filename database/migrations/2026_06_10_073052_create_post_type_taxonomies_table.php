<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_type_taxonomies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('post_type_id')
                ->constrained('post_types')
                ->cascadeOnDelete();

            $table->foreignId('taxonomy_id')
                ->constrained('taxonomies')
                ->cascadeOnDelete();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);

            $table->timestamps();

            $table->unique(
                ['post_type_id', 'taxonomy_id'],
                'unique_post_type_taxonomy'
            );

            $table->index(['post_type_id', 'status']);
            $table->index(['taxonomy_id', 'status']);
            $table->index(['sort_order', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_type_taxonomies');
    }
};