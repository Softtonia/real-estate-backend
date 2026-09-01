<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_batch_items')) {
            Schema::table('media_batch_items', function (Blueprint $table) {
                if (!Schema::hasColumn('media_batch_items', 'file_name')) {
                    $table->string('file_name')->nullable()->after('media_file_id');
                }
                if (!Schema::hasColumn('media_batch_items', 'original_name')) {
                    $table->string('original_name')->nullable()->after('file_name');
                }
                if (!Schema::hasColumn('media_batch_items', 'mime_type')) {
                    $table->string('mime_type', 100)->nullable()->after('original_name');
                }
                if (!Schema::hasColumn('media_batch_items', 'extension')) {
                    $table->string('extension', 30)->nullable()->after('mime_type');
                }
                if (!Schema::hasColumn('media_batch_items', 'size')) {
                    $table->unsignedBigInteger('size')->default(0)->after('extension');
                }
                if (!Schema::hasColumn('media_batch_items', 'path')) {
                    $table->string('path', 500)->nullable()->after('size');
                }
                if (!Schema::hasColumn('media_batch_items', 'url')) {
                    $table->string('url', 1000)->nullable()->after('path');
                }
                if (!Schema::hasColumn('media_batch_items', 'is_featured')) {
                    $table->boolean('is_featured')->default(false)->after('url');
                }
                if (!Schema::hasColumn('media_batch_items', 'sort_order')) {
                    $table->integer('sort_order')->default(0)->after('is_featured');
                }
                if (!Schema::hasColumn('media_batch_items', 'status')) {
                    $table->string('status', 30)->default('pending')->after('sort_order');
                }
                if (!Schema::hasColumn('media_batch_items', 'error_message')) {
                    $table->text('error_message')->nullable()->after('status');
                }
                if (!Schema::hasColumn('media_batch_items', 'attempts')) {
                    $table->unsignedTinyInteger('attempts')->default(0)->after('error_message');
                }
                if (!Schema::hasColumn('media_batch_items', 'metadata')) {
                    $table->json('metadata')->nullable()->after('attempts');
                }
            });
        }
    }

    public function down(): void
    {
    }
};
