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

            $table->string('slug')->unique();

            // Selected post type: property / developer / project
            $table->unsignedBigInteger('keyword_type');

            // Dependent selected listing from dynamic_posts
            $table->unsignedBigInteger('post_type');

            // Comma separated keywords stored as JSON array
            $table->json('keyword_list')->nullable();

            $table->timestamps();

            $table->foreign('keyword_type')
                ->references('id')
                ->on('post_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('post_type')
                ->references('id')
                ->on('dynamic_posts')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->index(['keyword_type', 'post_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keywords');
    }
};