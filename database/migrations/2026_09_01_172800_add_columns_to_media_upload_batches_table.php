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
                if (!Schema::hasColumn('media_upload_batches', 'post_type_slug')) {
                    $table->string('post_type_slug', 100)->nullable()->after('dynamic_post_id');
                }
                if (!Schema::hasColumn('media_upload_batches', 'custom_field_id')) {
                    $table->unsignedBigInteger('custom_field_id')->nullable()->after('post_type_slug');
                }
                if (!Schema::hasColumn('media_upload_batches', 'field_slug')) {
                    $table->string('field_slug', 100)->nullable()->after('custom_field_id');
                }
                if (!Schema::hasColumn('media_upload_batches', 'context')) {
                    $table->string('context', 50)->default('custom-fields')->after('field_slug');
                }
                if (!Schema::hasColumn('media_upload_batches', 'expected_count')) {
                    $table->unsignedInteger('expected_count')->default(0)->after('context');
                }
                if (!Schema::hasColumn('media_upload_batches', 'uploaded_count')) {
                    $table->unsignedInteger('uploaded_count')->default(0)->after('expected_count');
                }
                if (!Schema::hasColumn('media_upload_batches', 'processed_count')) {
                    $table->unsignedInteger('processed_count')->default(0)->after('uploaded_count');
                }
                if (!Schema::hasColumn('media_upload_batches', 'failed_count')) {
                    $table->unsignedInteger('failed_count')->default(0)->after('processed_count');
                }
                if (!Schema::hasColumn('media_upload_batches', 'status')) {
                    $table->string('status', 30)->default('initiated')->after('failed_count');
                }
                if (!Schema::hasColumn('media_upload_batches', 'progress_percent')) {
                    $table->decimal('progress_percent', 5, 2)->default(0.00)->after('status');
                }
                if (!Schema::hasColumn('media_upload_batches', 'metadata')) {
                    $table->json('metadata')->nullable()->after('progress_percent');
                }
                if (!Schema::hasColumn('media_upload_batches', 'expires_at')) {
                    $table->timestamp('expires_at')->nullable()->after('metadata');
                }
            });
        }
    }

    public function down(): void
    {
    }
};
