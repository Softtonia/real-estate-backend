<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomy_terms', function (Blueprint $table) {
            $table->id();

            $table->foreignId('taxonomy_id')
                ->constrained('taxonomies')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('parent_id')
                ->nullable()
                ->comment('Parent term ID for hierarchical taxonomy terms');

            $table->string('name', 150);
            $table->string('slug', 150);
            $table->text('description')->nullable();

            $table->unsignedInteger('sort_order')
                ->default(0)
                ->comment('Controls display ordering of taxonomy terms');

            $table->boolean('status')
                ->default(true)
                ->comment('1 = active, 0 = inactive');

            $table->timestamps();

            $table->unique(['taxonomy_id', 'slug']);

            $table->foreign('parent_id')
                ->references('id')
                ->on('taxonomy_terms')
                ->nullOnDelete();
            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->index(['taxonomy_id', 'status']);
            $table->index(['taxonomy_id', 'parent_id']);
            $table->index(['sort_order', 'status']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_terms');
    }
};
