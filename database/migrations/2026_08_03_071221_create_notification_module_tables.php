<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | User Firebase Devices
        |--------------------------------------------------------------------------
        */
        if (! Schema::hasTable('notification_devices')) {
            Schema::create('notification_devices', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->string('fcm_token', 512)->unique();

                $table->string('platform', 20)->index(); 
                // android, ios, web

                $table->string('app_type', 100)->nullable()->index();
                $table->string('device_id', 191)->nullable()->index();
                $table->string('device_name', 191)->nullable();

                $table->string('browser', 100)->nullable();
                $table->string('os', 100)->nullable();

                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();

                $table->boolean('status')->default(true)->index();
                $table->timestamp('last_used_at')->nullable()->index();
                $table->timestamp('revoked_at')->nullable()->index();

                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index(['app_type', 'platform', 'status']);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Notification Templates
        |--------------------------------------------------------------------------
        */
        if (! Schema::hasTable('notification_templates')) {
            Schema::create('notification_templates', function (Blueprint $table) {
                $table->id();

                $table->string('template_key', 191)->unique();
                $table->string('title', 255);
                $table->text('body');

                $table->string('image_url', 1000)->nullable();
                $table->json('data')->nullable();

                $table->string('channel', 50)->default('push')->index();
                // push, database, push_database

                $table->boolean('status')->default(true)->index();

                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->foreignId('updated_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamps();

                $table->index(['channel', 'status']);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Notification Batches
        |--------------------------------------------------------------------------
        */
        if (! Schema::hasTable('notification_batches')) {
            Schema::create('notification_batches', function (Blueprint $table) {
                $table->id();

                $table->uuid('batch_uuid')->unique();

                $table->foreignId('template_id')
                    ->nullable()
                    ->constrained('notification_templates')
                    ->nullOnDelete();

                $table->string('title', 255);
                $table->text('body');
                $table->string('image_url', 1000)->nullable();

                $table->string('target_type', 50)->index();
                // all, role, user, users, topic, token

                $table->longText('target_value')->nullable();

                $table->json('payload')->nullable();

                $table->unsignedInteger('total_count')->default(0);
                $table->unsignedInteger('success_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);

                $table->string('status', 50)->default('pending')->index();
                // pending, processing, completed, failed, cancelled, scheduled

                $table->timestamp('scheduled_at')->nullable()->index();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();

                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamps();

                $table->index(['target_type', 'status']);
                $table->index(['status', 'scheduled_at']);
                $table->index(['created_by', 'created_at']);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Notification Logs
        |--------------------------------------------------------------------------
        */
        if (! Schema::hasTable('notification_logs')) {
            Schema::create('notification_logs', function (Blueprint $table) {
                $table->id();

                $table->foreignId('batch_id')
                    ->nullable()
                    ->constrained('notification_batches')
                    ->nullOnDelete();

                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->foreignId('device_id')
                    ->nullable()
                    ->constrained('notification_devices')
                    ->nullOnDelete();

                $table->string('fcm_token', 512)->nullable();
                $table->string('platform', 20)->nullable()->index();

                $table->string('title', 255);
                $table->text('body')->nullable();

                $table->json('payload')->nullable();

                $table->string('firebase_message_id', 500)->nullable();

                $table->string('status', 50)->default('pending')->index();
                // pending, sent, failed, skipped

                $table->string('error_code', 191)->nullable()->index();
                $table->text('error_message')->nullable();

                $table->timestamp('sent_at')->nullable()->index();

                $table->timestamps();

                $table->index(['batch_id', 'status']);
                $table->index(['user_id', 'status']);
                $table->index(['device_id', 'status']);
                $table->index(['created_at', 'status']);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | User Notification Inbox
        |--------------------------------------------------------------------------
        */
        if (! Schema::hasTable('user_notifications')) {
            Schema::create('user_notifications', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->foreignId('batch_id')
                    ->nullable()
                    ->constrained('notification_batches')
                    ->nullOnDelete();

                $table->string('title', 255);
                $table->text('body');

                $table->string('image_url', 1000)->nullable();
                $table->json('data')->nullable();

                $table->string('type', 100)->default('general')->index();

                $table->timestamp('read_at')->nullable()->index();

                $table->timestamps();

                $table->index(['user_id', 'read_at']);
                $table->index(['user_id', 'created_at']);
                $table->index(['user_id', 'type']);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Notification Topics
        |--------------------------------------------------------------------------
        */
        if (! Schema::hasTable('notification_topics')) {
            Schema::create('notification_topics', function (Blueprint $table) {
                $table->id();

                $table->string('name', 191);
                $table->string('slug', 191)->unique();

                $table->text('description')->nullable();

                $table->boolean('status')->default(true)->index();

                $table->timestamps();
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Notification Topic Subscribers
        |--------------------------------------------------------------------------
        */
        if (! Schema::hasTable('notification_topic_subscribers')) {
            Schema::create('notification_topic_subscribers', function (Blueprint $table) {
                $table->id();

                $table->foreignId('topic_id')
                    ->constrained('notification_topics')
                    ->cascadeOnDelete();

                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->foreignId('device_id')
                    ->nullable()
                    ->constrained('notification_devices')
                    ->cascadeOnDelete();

                $table->boolean('status')->default(true)->index();

                $table->timestamps();

                $table->unique(['topic_id', 'user_id', 'device_id'], 'topic_user_device_unique');
                $table->index(['topic_id', 'status']);
                $table->index(['user_id', 'status']);
                $table->index(['device_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_topic_subscribers');
        Schema::dropIfExists('notification_topics');
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_batches');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notification_devices');
    }
};