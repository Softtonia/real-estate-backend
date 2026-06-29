<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_api_ips', function (Blueprint $table) {
            $table->id();

            $table->string('ip_address', 45)->unique();
            $table->string('reason', 255)->nullable();

            $table->boolean('permanent')->default(false);
            $table->timestamp('blocked_until')->nullable();

            $table->timestamps();

            $table->index(['permanent', 'blocked_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_api_ips');
    }
};