<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('media_upload_batches')) {
            Schema::create('media_upload_batches', function (Blueprint $table) {
                $table->id();
                $table->string('batch_uuid', 36)->unique();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('post_type_id')->nullable()->index();
                $table->unsignedBigInteger('custom_field_id')->nullable()->index();
                $table->string('field_slug', 100)->nullable()->index();
                $table->unsignedInteger('expected_count')->default(1);
                $table->unsignedInteger('uploaded_count')->default(0);
                $table->unsignedInteger('processed_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->string('status', 30)->default('pending')->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('media_batch_items')) {
            Schema::create('media_batch_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('batch_id')->index();
                $table->unsignedBigInteger('media_file_id')->nullable()->index();
                $table->string('client_file_id', 100)->nullable()->index();
                $table->string('original_name', 255)->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->string('mime_type', 100)->nullable();
                $table->boolean('is_featured')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('status', 30)->default('pending')->index();
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->foreign('batch_id')
                    ->references('id')
                    ->on('media_upload_batches')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_batch_items');
        Schema::dropIfExists('media_upload_batches');
    }
};
