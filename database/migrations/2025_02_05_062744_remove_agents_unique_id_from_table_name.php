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
        Schema::dropIfExists('agents_unique_id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('agents_unique_id', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};
