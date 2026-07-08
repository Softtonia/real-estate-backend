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

            /*
             * post_type = keyword belongs to full post type
             * dynamic_post = keyword belongs to specific listing/post
             */
            $table->enum('keyword_type', ['post_type', 'dynamic_post'])
                ->default('post_type')
                ->index();

            $table->foreignId('post_type_id')
                ->constrained('post_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('dynamic_post_id')
                ->nullable()
                ->constrained('dynamic_posts')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->json('keyword_list')->nullable();

            /*
             * Import tracking
             */
            $table->uuid('import_uid')->nullable()->unique();
            $table->string('import_file_key')->nullable()->index();
            $table->unsignedInteger('import_row_number')->nullable();
            $table->uuid('last_import_batch_id')->nullable()->index();

            $table->timestamps();

            $table->unique(['import_file_key', 'import_row_number'], 'keywords_file_row_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keywords');
    }
};