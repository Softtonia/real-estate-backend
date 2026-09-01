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
                $table->uuid('batch_uuid')->unique();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('dynamic_post_id')->nullable()->index();
                $table->string('post_type_slug', 100)->nullable()->index();
                $table->unsignedBigInteger('custom_field_id')->nullable()->index();
                $table->string('field_slug', 100)->nullable()->index();
                $table->string('context', 50)->default('custom-fields');
                $table->unsignedInteger('expected_count')->default(0);
                $table->unsignedInteger('uploaded_count')->default(0);
                $table->unsignedInteger('processed_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->string('status', 30)->default('initiated')->index();
                $table->decimal('progress_percent', 5, 2)->default(0.00);
                $table->json('metadata')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('media_batch_items')) {
            Schema::create('media_batch_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('batch_id');
                $table->string('client_file_id', 100);
                $table->unsignedBigInteger('media_file_id')->nullable()->index();
                $table->string('file_name');
                $table->string('original_name');
                $table->string('mime_type', 100)->nullable();
                $table->string('extension', 30)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->string('path', 500);
                $table->string('url', 1000)->nullable();
                $table->boolean('is_featured')->default(false);
                $table->integer('sort_order')->default(0);
                $table->string('status', 30)->default('pending')->index();
                $table->text('error_message')->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('batch_id')
                    ->references('id')
                    ->on('media_upload_batches')
                    ->onDelete('cascade');

                $table->unique(['batch_id', 'client_file_id']);
            });
        } else {
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
        Schema::dropIfExists('media_batch_items');
        Schema::dropIfExists('media_upload_batches');
    }
};
