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
        Schema::create('pricing', function (Blueprint $table) {
            $table->id();
            $table->decimal('price', 10, 2); // Assuming 10 digits with 2 decimal places for price
            $table->text('price_breakup')->nullable();
            $table->decimal('booking_amount', 10, 2); // Assuming 10 digits with 2 decimal places for booking amount
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing');
    }
};
