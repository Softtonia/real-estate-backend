<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_upload_batches')) {
            Schema::table('media_upload_batches', function (Blueprint $table) {
                if (!Schema::hasColumn('media_upload_batches', 'batch_uuid')) {
                    $table->uuid('batch_uuid')->nullable()->unique();
                }
                if (!Schema::hasColumn('media_upload_batches', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable()->index();
                }
                if (!Schema::hasColumn('media_upload_batches', 'dynamic_post_id')) {
                    $table->unsignedBigInteger('dynamic_post_id')->nullable()->index();
                }
                if (!Schema::hasColumn('media_upload_batches', 'post_type_slug')) {
                    $table->string('post_type_slug', 100)->nullable()->index();
                }
                if (!Schema::hasColumn('media_upload_batches', 'custom_field_id')) {
                    $table->unsignedBigInteger('custom_field_id')->nullable()->index();
                }
                if (!Schema::hasColumn('media_upload_batches', 'field_slug')) {
                    $table->string('field_slug', 100)->nullable()->index();
                }
                if (!Schema::hasColumn('media_upload_batches', 'context')) {
                    $table->string('context', 50)->default('custom-fields');
                }
                if (!Schema::hasColumn('media_upload_batches', 'expected_count')) {
                    $table->unsignedInteger('expected_count')->default(0);
                }
                if (!Schema::hasColumn('media_upload_batches', 'uploaded_count')) {
                    $table->unsignedInteger('uploaded_count')->default(0);
                }
                if (!Schema::hasColumn('media_upload_batches', 'processed_count')) {
                    $table->unsignedInteger('processed_count')->default(0);
                }
                if (!Schema::hasColumn('media_upload_batches', 'failed_count')) {
                    $table->unsignedInteger('failed_count')->default(0);
                }
                if (!Schema::hasColumn('media_upload_batches', 'status')) {
                    $table->string('status', 30)->default('initiated')->index();
                }
                if (!Schema::hasColumn('media_upload_batches', 'progress_percent')) {
                    $table->decimal('progress_percent', 5, 2)->default(0.00);
                }
                if (!Schema::hasColumn('media_upload_batches', 'metadata')) {
                    $table->json('metadata')->nullable();
                }
                if (!Schema::hasColumn('media_upload_batches', 'expires_at')) {
                    $table->timestamp('expires_at')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
    }
};
