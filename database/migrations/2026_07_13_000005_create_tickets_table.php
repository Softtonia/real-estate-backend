<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 200)->unique();

            $table->foreignId('raised_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('subject', 200)->nullable();
            $table->longText('message');

            $table->foreignId('status_id')
                ->nullable()
                ->constrained('ticket_status')
                ->nullOnDelete();

            $table->foreignId('priority_id')
                ->nullable()
                ->constrained('ticket_priorities')
                ->nullOnDelete();

            $table->foreignId('ticket_type_id')
                ->nullable()
                ->constrained('ticket_types')
                ->nullOnDelete();

            $table->foreignId('ticket_department_id')
                ->nullable()
                ->constrained('ticket_departments')
                ->nullOnDelete();

            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('property_id')->nullable()->index();

            // Kept only for backward compatibility. New files use ticket_attachments.
            $table->string('media_attachment', 500)->nullable();

            $table->timestamps();

            $table->index(['status_id', 'priority_id']);
            $table->index(['raised_by', 'user_id']);
        });

        // This keeps the migration usable even when the property module uses
        // a later migration. Add the FK only when the table already exists.
        if (Schema::hasTable('properties')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->foreign('property_id')
                    ->references('id')
                    ->on('properties')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
