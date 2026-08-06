<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dynamic_posts', function (Blueprint $table) {
            $table->string('availability_status', 32)
                ->default('available')
                ->after('live_status');

            $table->timestamp('availability_changed_at')
                ->nullable()
                ->after('availability_status');

            $table->unsignedBigInteger('availability_changed_by')
                ->nullable()
                ->after('availability_changed_at');

            $table->text('availability_notes')
                ->nullable()
                ->after('availability_changed_by');

            $table->timestamp('availability_public_until')
                ->nullable()
                ->after('availability_notes');

            $table->timestamp('availability_hidden_at')
                ->nullable()
                ->after('availability_public_until');

            $table->timestamp('sold_at')
                ->nullable()
                ->after('availability_hidden_at');

            $table->unsignedBigInteger('sold_by')
                ->nullable()
                ->after('sold_at');

            $table->index(
                ['availability_status'],
                'idx_dynamic_posts_availability'
            );

            $table->index(
                [
                    'availability_status',
                    'availability_public_until',
                    'availability_hidden_at',
                ],
                'idx_dynamic_posts_sold_visibility'
            );

            $table->index(
                [
                    'post_type_id',
                    'status',
                    'live_status',
                    'availability_status',
                ],
                'idx_dynamic_posts_public_availability'
            );

            $table->foreign(
                'availability_changed_by',
                'fk_dynamic_posts_availability_changed_by'
            )
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign(
                'sold_by',
                'fk_dynamic_posts_sold_by'
            )
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dynamic_posts', function (Blueprint $table) {
            $table->dropForeign(
                'fk_dynamic_posts_availability_changed_by'
            );

            $table->dropForeign(
                'fk_dynamic_posts_sold_by'
            );

            $table->dropIndex(
                'idx_dynamic_posts_availability'
            );

            $table->dropIndex(
                'idx_dynamic_posts_sold_visibility'
            );

            $table->dropIndex(
                'idx_dynamic_posts_public_availability'
            );

            $table->dropColumn([
                'availability_status',
                'availability_changed_at',
                'availability_changed_by',
                'availability_notes',
                'availability_public_until',
                'availability_hidden_at',
                'sold_at',
                'sold_by',
            ]);
        });
    }
};