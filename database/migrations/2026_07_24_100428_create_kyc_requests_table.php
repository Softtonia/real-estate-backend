<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('role_id')
                ->nullable()
                ->constrained('roles')
                ->nullOnDelete();

            $table->unsignedBigInteger('parent_kyc_request_id')->nullable();

            $table->unsignedSmallInteger('version')->default(1);

            $table->string('status', 30)
                ->default('draft')
                ->comment('draft, submitted, under_review, approved, rejected, resubmitted');

            $table->string('aadhaar_number', 20)->nullable();
            $table->string('gst_number', 30)->nullable();
            $table->string('rera_number', 100)->nullable();
            $table->string('business_name')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('review_started_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('rejection_reason')->nullable();
            $table->text('reviewer_notes')->nullable();

            $table->unsignedInteger('resubmission_count')->default(0);

            $table->string('submitted_ip', 45)->nullable();
            $table->string('reviewed_ip', 45)->nullable();

            $table->timestamps();

            $table->foreign('parent_kyc_request_id')
                ->references('id')
                ->on('kyc_requests')
                ->nullOnDelete();

            $table->index('user_id');
            $table->index('role_id');
            $table->index('status');
            $table->index('reviewed_by');
            $table->index('submitted_at');
            $table->index('reviewed_at');
            $table->index(['user_id', 'status']);
            $table->index(['role_id', 'status']);
            $table->index(['status', 'submitted_at']);
            $table->index(['reviewed_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_requests');
    }
};