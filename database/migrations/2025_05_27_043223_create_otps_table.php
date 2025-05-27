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
        Schema::create('otps', function (Blueprint $table) {
            $table->id();
            $table->string('otp', 200)->nullable(); // VARCHAR(200), NULL
            $table->string('user_id', 200)->nullable(); // VARCHAR(200), NULL
            $table->string('isOTPVerified', 255)->nullable(); // VARCHAR(255), NULL

            $table->timestamp('expire_date_time')->useCurrent(); // default current_timestamp
            $table->timestamp('created_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('updated_at')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otps');
    }
};
