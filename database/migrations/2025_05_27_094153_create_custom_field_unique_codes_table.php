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
        Schema::create('custom_field_unique_codes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->collation('utf8mb4_general_ci')->nullable();
            $table->string('slug', 100)->collation('utf8mb4_general_ci')->nullable();
            $table->string('type', 30)->collation('utf8mb4_general_ci')->default('project')->nullable();

            $table->enum('status', ['1', '0'])->collation('utf8mb4_general_ci')->default('1');
            $table->timestamp('created_at')->useCurrent()->nullable();
            $table->timestamp('updated_at')->useCurrent()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_field_unique_codes');
    }
};
