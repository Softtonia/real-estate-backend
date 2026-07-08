<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keywords', function (Blueprint $table) {
            $table->id();

            $table->string('keyword');

            $table->enum('status', ['active', 'inactive'])
                ->default('active')
                ->index();

            $table->unsignedInteger('avg_search_volume')->nullable();
            $table->decimal('avg_ranking', 8, 2)->nullable();

            $table->timestamps();

            $table->index('keyword');
        });

        Schema::create('keyword_post_type', function (Blueprint $table) {
            $table->id();

            $table->foreignId('keyword_id')
                ->constrained('keywords')
                ->cascadeOnDelete();

            $table->foreignId('post_type_id')
                ->constrained('post_types')
                ->cascadeOnDelete();

            $table->unique(['keyword_id', 'post_type_id'], 'keyword_post_type_unique');
        });

        Schema::create('keyword_dynamic_post', function (Blueprint $table) {
            $table->id();

            $table->foreignId('keyword_id')
                ->constrained('keywords')
                ->cascadeOnDelete();

            $table->foreignId('dynamic_post_id')
                ->constrained('dynamic_posts')
                ->cascadeOnDelete();

            $table->unique(['keyword_id', 'dynamic_post_id'], 'keyword_dynamic_post_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_dynamic_post');
        Schema::dropIfExists('keyword_post_type');
        Schema::dropIfExists('keywords');
    }
};