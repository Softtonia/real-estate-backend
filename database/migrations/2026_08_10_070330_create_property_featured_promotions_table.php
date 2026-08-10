<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_featured_promotions', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Property
            |--------------------------------------------------------------------------
            */
            $table->foreignId('dynamic_post_id')
                ->constrained('dynamic_posts')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Promotion source
            |--------------------------------------------------------------------------
            |
            | admin      = manually featured by admin/staff
            | membership = later membership/credit based feature
            |
            */
            $table->string('source', 30)
                ->default('admin');

            /*
            |--------------------------------------------------------------------------
            | Promotion status
            |--------------------------------------------------------------------------
            |
            | scheduled
            | active
            | expired
            | cancelled
            |
            */
            $table->string('status', 30)
                ->default('scheduled');

            /*
            |--------------------------------------------------------------------------
            | Featured duration
            |--------------------------------------------------------------------------
            */
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            /*
            |--------------------------------------------------------------------------
            | Priority
            |--------------------------------------------------------------------------
            |
            | Higher number can be shown first on frontend.
            |
            */
            $table->unsignedInteger('priority')
                ->default(0);

            /*
            |--------------------------------------------------------------------------
            | Admin notes
            |--------------------------------------------------------------------------
            */
            $table->text('admin_notes')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Audit users
            |--------------------------------------------------------------------------
            */
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Cancellation audit
            |--------------------------------------------------------------------------
            */
            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('cancelled_at')
                ->nullable();

            $table->text('cancellation_reason')
                ->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            // Find promotions for a property quickly.
            $table->index(
                ['dynamic_post_id', 'status'],
                'pfp_property_status_idx'
            );

            // Public/admin active and scheduled queries.
            $table->index(
                ['status', 'starts_at', 'ends_at'],
                'pfp_status_dates_idx'
            );

            // Featured ordering.
            $table->index(
                ['status', 'priority'],
                'pfp_status_priority_idx'
            );

            // Admin vs membership promotions.
            $table->index(
                ['source', 'status'],
                'pfp_source_status_idx'
            );

            $table->index(
                'ends_at',
                'pfp_ends_at_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'property_featured_promotions'
        );
    }
};