<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_group_location_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('custom_field_group_id')
                ->constrained('custom_field_groups')
                ->cascadeOnDelete();

            $table->enum('show_if', [
                'post_type',
                'taxonomy'
            ]);

            $table->enum('match_type', [
                'all',
                'specific'
            ])->default('specific');

            $table->foreignId('post_type_id')
                ->nullable()
                ->constrained('post_types')
                ->nullOnDelete();

            $table->foreignId('taxonomy_id')
                ->nullable()
                ->constrained('taxonomies')
                ->nullOnDelete();

            $table->json('taxonomy_term_ids')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);

            $table->timestamps();

            $table->index(['custom_field_group_id', 'show_if'], 'idx_cfglr_group_show_if');
            $table->index(['post_type_id', 'status'], 'idx_cfglr_post_type_status');
            $table->index(['taxonomy_id', 'status'], 'idx_cfglr_taxonomy_status');
            $table->index(['sort_order', 'status'], 'idx_cfglr_sort_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_group_location_rules');
    }
};