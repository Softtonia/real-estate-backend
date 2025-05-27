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
        Schema::create('custom_field_options', function (Blueprint $table) {
            $table->id();
            $table->integer('custom_field_id')->nullable();
            $table->string('type', 100)->collation('latin1_swedish_ci')->nullable();
            $table->string('name', 100)->collation('latin1_swedish_ci')->nullable();
            $table->string('value', 100)->collation('latin1_swedish_ci')->nullable();
            $table->enum('status', ['1', '0'])->default('1')->collation('latin1_swedish_ci');
            $table->timestamp('created_at')->useCurrent()->nullable();
            $table->timestamp('updated_at')->useCurrent()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_field_options');
    }
};
