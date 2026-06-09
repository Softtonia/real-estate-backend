<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_conditions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('custom_field_id')
                ->constrained('custom_fields')
                ->cascadeOnDelete();

            $table->foreignId('taxonomy_id')
                ->constrained('taxonomies')
                ->cascadeOnDelete();

            $table->foreignId('taxonomy_term_id')
                ->constrained('taxonomy_terms')
                ->cascadeOnDelete();

            $table->enum('operator', [
                'include',
                'exclude'
            ])->default('include');

            $table->timestamps();

            $table->unique(
                ['custom_field_id', 'taxonomy_id', 'taxonomy_term_id', 'operator'],
                'uq_custom_field_condition'
            );

            $table->index(
                ['taxonomy_id', 'taxonomy_term_id', 'custom_field_id'],
                'idx_condition_taxonomy_term_field'
            );

            $table->index(
                ['custom_field_id', 'operator'],
                'idx_custom_field_operator'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_conditions');
    }
};