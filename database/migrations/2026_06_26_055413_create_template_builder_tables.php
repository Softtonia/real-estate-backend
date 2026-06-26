<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();

            $table->enum('template_type', ['single_post', 'page', 'section'])
                ->default('single_post');

            $table->unsignedBigInteger('post_type_id')->nullable();
            $table->string('post_type_slug', 150)->nullable();

            $table->string('template_name');
            $table->string('slug')->unique();
            $table->string('shortcode')->nullable()->unique();

            $table->enum('status', ['active', 'draft'])->default('draft');
            $table->integer('priority')->default(0);

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('template_type');
            $table->index('post_type_id');
            $table->index('post_type_slug');
            $table->index('status');
            $table->index('priority');

            // Post type relation
            $table->foreign('post_type_id')
                ->references('id')
                ->on('post_types')
                ->nullOnDelete();
        });

        Schema::create('template_layouts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('template_id');
            $table->json('layout_json')->nullable();

            $table->timestamps();

            $table->unique('template_id');

            $table->foreign('template_id')
                ->references('id')
                ->on('templates')
                ->onDelete('cascade');
        });

        Schema::create('template_display_conditions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('template_id');

            $table->enum('show_type', ['include', 'exclude'])
                ->default('include');

            $table->enum('source_type', ['post_type', 'taxonomy'])
                ->default('post_type');

            $table->unsignedBigInteger('post_type_id')->nullable();
            $table->string('post_type_slug', 150)->nullable();

            $table->unsignedBigInteger('taxonomy_id')->nullable();
            $table->string('taxonomy_slug', 150)->nullable();

            // Taxonomy terms multiple ho sakte hain, isliye JSON me save honge
            $table->json('taxonomy_term_ids')->nullable();

            $table->enum('relation', ['and', 'or'])
                ->default('and');

            $table->json('condition_value')->nullable();

            $table->timestamps();

            $table->index('template_id');
            $table->index('show_type');
            $table->index('source_type');
            $table->index('post_type_id');
            $table->index('post_type_slug');
            $table->index('taxonomy_id');
            $table->index('taxonomy_slug');

            // Template relation
            $table->foreign('template_id')
                ->references('id')
                ->on('templates')
                ->onDelete('cascade');

            // Post type relation
            $table->foreign('post_type_id')
                ->references('id')
                ->on('post_types')
                ->nullOnDelete();

            // Taxonomy relation
            $table->foreign('taxonomy_id')
                ->references('id')
                ->on('taxonomies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_display_conditions');
        Schema::dropIfExists('template_layouts');
        Schema::dropIfExists('templates');
    }
};