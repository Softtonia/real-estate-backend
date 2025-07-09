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
        Schema::create('custom_field_repeater_values', function (Blueprint $table) {
            $table->id('custom_repeater_value_id');
            $table->unsignedBigInteger('developer_listing_id')->nullable();
            $table->unsignedBigInteger('custom_field_id')->nullable();
            $table->unsignedBigInteger('custom_field_repeater_id')->nullable();
            $table->string('custom_field_repeater_options_id')->nullable();
            $table->string('field_type', 255)->collation('utf8mb4_general_ci')->nullable();
            $table->text('field_meta_value')->collation('utf8mb4_general_ci')->nullable();
            $table->string('unique_id', 30)->collation('utf8mb4_general_ci')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_field_repeater_values');
    }
};
