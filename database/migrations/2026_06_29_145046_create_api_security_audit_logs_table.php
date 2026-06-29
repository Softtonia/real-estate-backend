<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_security_audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('api_client_id')
                ->nullable()
                ->constrained('api_clients')
                ->nullOnDelete();

            $table->foreignId('application_password_id')
                ->nullable()
                ->constrained('application_passwords')
                ->nullOnDelete();

            $table->string('event', 100)->index();

            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('actor_type', 50)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['api_client_id', 'created_at']);
            $table->index(['application_password_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_security_audit_logs');
    }
};