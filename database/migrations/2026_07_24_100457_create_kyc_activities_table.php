<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_activities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('kyc_request_id')
                ->nullable()
                ->constrained('kyc_requests')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('performed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('action', 80)
                ->comment('submitted, under_review, approved, rejected, resubmitted, document_uploaded');

            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30)->nullable();

            $table->text('remarks')->nullable();
            $table->longText('metadata')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->index('kyc_request_id');
            $table->index('user_id');
            $table->index('performed_by');
            $table->index('action');
            $table->index('created_at');
            $table->index(['kyc_request_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_activities');
    }
};