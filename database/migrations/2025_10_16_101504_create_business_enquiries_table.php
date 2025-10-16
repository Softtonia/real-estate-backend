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
        Schema::create('business_enquiries', function (Blueprint $table) {
            $table->id();
             $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone_no');
            $table->string('email')->nullable();
            $table->text('message')->nullable();
            $table->enum('status', ['new', 'in_progress', 'closed'])->default('new');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_enquiries');
    }
};
