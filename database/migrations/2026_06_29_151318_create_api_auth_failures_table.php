<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_auth_failures', function (Blueprint $table) {
            $table->id();

            $table->foreignId('api_client_id')
                ->nullable()
                ->constrained('api_clients')
                ->nullOnDelete();

            $table->string('reason', 100)->index();

            $table->string('token_prefix', 32)->nullable()->index();

            $table->string('ip_address', 45)->nullable()->index();
            $table->text('user_agent')->nullable();

            $table->string('method', 10)->nullable();
            $table->text('path')->nullable();

            $table->string('origin', 255)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['api_client_id', 'created_at']);
            $table->index(['ip_address', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_auth_failures');
    }
};