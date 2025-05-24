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
        Schema::create('role_prefix_reapeaters', function (Blueprint $table) {
            $table->id();
            $table->interface('role_id');
            $table->string('role_prefix');
            $table->string('role_prefix_slug');
            $table->string('created_by');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_prefix_reapeaters');
    }
};
