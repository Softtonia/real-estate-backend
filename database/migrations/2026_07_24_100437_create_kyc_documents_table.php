<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('kyc_request_id')
                ->constrained('kyc_requests')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('document_type', 60)
                ->comment('aadhaar_front, aadhaar_back, gst_certificate, rera_certificate, business_proof, other');

            $table->string('document_number', 100)->nullable();

            $table->string('file_disk', 50)->default('kyc_private');
            $table->string('file_path', 500);
            $table->string('file_original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);

            $table->string('status', 30)
                ->default('pending')
                ->comment('pending, approved, rejected');

            $table->text('rejection_reason')->nullable();

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->unsignedSmallInteger('version')->default(1);

            $table->longText('metadata')->nullable();

            $table->timestamps();

            $table->index('kyc_request_id');
            $table->index('user_id');
            $table->index('document_type');
            $table->index('status');
            $table->index('uploaded_at');
            $table->index('reviewed_at');
            $table->index(['kyc_request_id', 'document_type']);
            $table->index(['user_id', 'document_type']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');
    }
};