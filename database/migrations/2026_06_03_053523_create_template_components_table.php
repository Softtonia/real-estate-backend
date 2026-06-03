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
        Schema::create('template_components', function (Blueprint $table) {
            $table->id();
            $table->string('component_name');
            $table->string('component_key')->unique();
            $table->enum('component_type', ['static', 'dynamic'])->default('dynamic');
            $table->string('icon')->nullable();
            $table->json('config_json')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_components');
    }
};
