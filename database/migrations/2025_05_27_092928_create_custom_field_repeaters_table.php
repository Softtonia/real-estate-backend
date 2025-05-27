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
        Schema::create('custom_field_repeaters', function (Blueprint $table) {
            $table->id();
            $table->string('field_name_slug', 255)->collation('utf8mb4_general_ci')->index();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedBigInteger('custom_field_id')->nullable();
            $table->string('field_type', 30)->collation('utf8mb4_general_ci')->nullable();
            $table->string('field_label', 255)->collation('utf8mb4_general_ci')->nullable();
            $table->string('field_placeholder', 255)->collation('utf8mb4_general_ci')->nullable();
            $table->string('media_limit', 20)->collation('utf8mb4_general_ci')->nullable();
            $table->string('media_size', 20)->collation('utf8mb4_general_ci')->nullable();
            $table->string('media_format', 100)->collation('utf8mb4_general_ci')->nullable();
            $table->enum('status', ['1', '0'])->default('1')->collation('utf8mb4_general_ci');
            $table->timestamp('created_at')->useCurrent()->nullable();
            $table->timestamp('updated_at')->useCurrent()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_field_repeaters');
    }
};
