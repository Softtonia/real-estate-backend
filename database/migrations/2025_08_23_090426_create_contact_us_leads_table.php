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
        Schema::create('contact_us_leads', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('email')->index();
            $table->string('country_code', 5)->comment('E.g. +91, +1');
            $table->string('phone_number', 15)->comment('Phone number without country code');
            $table->text('message')->nullable();
            $table->enum('status', ['new', 'viewed', 'contacted', 'closed'])->default('new')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_us_leads');
    }
};
