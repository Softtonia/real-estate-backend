<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_name');
            $table->string('client_id')->unique();
            $table->string('client_secret');
            $table->enum('app-type', ['admin', 'business', 'website', 'mobile-app', 'custom'])->nullable();
            $table->enum('status', ['0', '1'])->comment('0 = inactive, 1 = active');
            $table->string('allowed_domain'); // e.g., https://frontend.com
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_clients');
    }
};
