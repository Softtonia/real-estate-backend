<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_passwords', function (Blueprint $table) {
            $table->id();

            $table->foreignId('api_client_id')
                ->constrained('api_clients')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('token_prefix', 32)->index();
            $table->char('token_hash', 64)->unique();

            $table->json('abilities')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->string('last_user_agent', 255)->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['api_client_id', 'revoked_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_passwords');
    }
};