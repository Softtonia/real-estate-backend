<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('taxonomy_terms', 'relation_with_taxonomy_id')) {
            Schema::table('taxonomy_terms', function (Blueprint $table) {
                $table->foreignId('relation_with_taxonomy_id')
                    ->nullable()
                    ->after('parent_id')
                    ->constrained('taxonomies')
                    ->nullOnDelete();
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Agar pehle single relation_value_term_id column ban gaya ho to remove kar do
        |--------------------------------------------------------------------------
        */

        if (Schema::hasColumn('taxonomy_terms', 'relation_value_term_id')) {
            Schema::table('taxonomy_terms', function (Blueprint $table) {
                $table->dropConstrainedForeignId('relation_value_term_id');
            });
        }

        if (!Schema::hasTable('taxonomy_term_relations')) {
            Schema::create('taxonomy_term_relations', function (Blueprint $table) {
                $table->id();

                $table->foreignId('taxonomy_term_id')
                    ->constrained('taxonomy_terms')
                    ->cascadeOnDelete();

                $table->foreignId('relation_with_taxonomy_id')
                    ->constrained('taxonomies')
                    ->cascadeOnDelete();

                $table->foreignId('relation_value_term_id')
                    ->constrained('taxonomy_terms')
                    ->cascadeOnDelete();

                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('status')->default(true);

                $table->timestamps();

                $table->unique(
                    ['taxonomy_term_id', 'relation_value_term_id'],
                    'taxonomy_term_relation_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_term_relations');

        if (Schema::hasColumn('taxonomy_terms', 'relation_with_taxonomy_id')) {
            Schema::table('taxonomy_terms', function (Blueprint $table) {
                $table->dropConstrainedForeignId('relation_with_taxonomy_id');
            });
        }
    }
};