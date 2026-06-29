<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_nonces', function (Blueprint $table) {
            $table->id();

            $table->foreignId('api_client_id')
                ->constrained('api_clients')
                ->cascadeOnDelete();

            $table->string('nonce', 128);
            $table->timestamp('expires_at');

            $table->timestamps();

            $table->unique(['api_client_id', 'nonce']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_nonces');
    }
};