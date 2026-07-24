<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_role_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('role_id')
                ->unique()
                ->constrained('roles')
                ->cascadeOnDelete();

            $table->boolean('requires_kyc')->default(true);
            $table->boolean('can_publish_without_kyc')->default(false);

            $table->text('required_documents')->nullable();

            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->index('requires_kyc');
            $table->index('can_publish_without_kyc');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_role_rules');
    }
};