<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('dynamic_posts');
        Schema::enableForeignKeyConstraints();

        Schema::create('dynamic_posts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('post_type_id')
                ->constrained('post_types')
                ->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('slug', 255);

            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable();
            $table->unsignedBigInteger('featured_image_id')->nullable();
            $table->json('gallery_image_ids')->nullable();
            $table->enum('status', [
                'draft',
                'published',
                'private',
                'archived',
            ])
                ->default('draft')
                ->comment('draft = save as draft, published = ready for publish/live, private = restricted, archived = inactive archive');
            $table->enum('live_status', [
                'approve',
                'reject',
                'under_review',
                'disapprove',
                'modify_review',
                'submit',
            ])
                ->nullable()
                ->comment('approve, reject, under_review, disapprove, modify_review, submit');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['post_type_id', 'slug'], 'unique_dynamic_post_type_slug');
            $table->foreign('author_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('parent_id')
                ->references('id')
                ->on('dynamic_posts')
                ->nullOnDelete();

            $table->index(['post_type_id', 'status', 'published_at'], 'idx_dynamic_posts_type_status_date');
            $table->index(['post_type_id', 'status'], 'idx_dynamic_posts_type_status');
            $table->index(['post_type_id', 'live_status'], 'idx_dynamic_posts_type_live_status');
            $table->index(['slug', 'status'], 'idx_dynamic_posts_slug_status');
            $table->index(['sort_order', 'status'], 'idx_dynamic_posts_sort_status');

            $table->index('author_id', 'idx_dynamic_posts_author');
            $table->index('parent_id', 'idx_dynamic_posts_parent');
            $table->index('featured_image_id', 'idx_dynamic_posts_featured_image');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('dynamic_posts');
        Schema::enableForeignKeyConstraints();
    }
};
