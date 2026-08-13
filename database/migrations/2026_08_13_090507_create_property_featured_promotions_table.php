<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'property_featured_promotions',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('dynamic_post_id')
                    ->constrained('dynamic_posts')
                    ->cascadeOnDelete();

                $table->string('source', 30)
                    ->default('admin');

                $table->string('promotion_type', 30)
                    ->default('featured');

                $table->boolean('show_on_home')
                    ->default(true);

                $table->boolean('show_on_search')
                    ->default(true);

                $table->boolean('show_on_detail')
                    ->default(true);

                $table->string('status', 30)
                    ->default('scheduled');

                $table->dateTime('starts_at')
                    ->nullable();

                $table->dateTime('ends_at')
                    ->nullable();

                $table->unsignedInteger('priority')
                    ->default(0);

                $table->text('admin_notes')
                    ->nullable();

                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->foreignId('updated_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->foreignId('cancelled_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->dateTime('cancelled_at')
                    ->nullable();

                $table->text('cancellation_reason')
                    ->nullable();

                $table->timestamps();

                $table->index(
                    [
                        'dynamic_post_id',
                        'status',
                    ],
                    'pfp_property_status_idx'
                );

                $table->index(
                    [
                        'status',
                        'starts_at',
                        'ends_at',
                    ],
                    'pfp_status_dates_idx'
                );

                $table->index(
                    [
                        'status',
                        'priority',
                    ],
                    'pfp_status_priority_idx'
                );

                $table->index(
                    [
                        'source',
                        'status',
                    ],
                    'pfp_source_status_idx'
                );

                $table->index(
                    [
                        'promotion_type',
                        'status',
                    ],
                    'pfp_type_status_idx'
                );

                $table->index(
                    [
                        'show_on_home',
                        'status',
                        'priority',
                    ],
                    'pfp_home_status_priority_idx'
                );

                $table->index(
                    [
                        'show_on_search',
                        'status',
                        'priority',
                    ],
                    'pfp_search_status_priority_idx'
                );

                $table->index(
                    [
                        'show_on_detail',
                        'status',
                        'priority',
                    ],
                    'pfp_detail_status_priority_idx'
                );

                $table->index(
                    'ends_at',
                    'pfp_ends_at_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'property_featured_promotions'
        );
    }
};