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
        Schema::create('user_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('country_id')->nullable()->index();
            $table->unsignedBigInteger('state_id')->nullable()->index();
            $table->unsignedBigInteger('city_id')->nullable()->index();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('created_by')->default(0);
            $table->unsignedBigInteger('role_id')->nullable();

            $table->string('bussiness_name', 200)->nullable();
            $table->string('business_phone', 200)->nullable();
            $table->string('bussiness_email', 200)->nullable();
            $table->string('bussiness_address', 200)->nullable();
            $table->string('address', 100)->nullable();
            $table->string('pin_code', 200)->nullable();
            $table->string('profile_photo', 100)->nullable();
            $table->string('license_number', 200)->nullable();
            $table->string('alternate_number', 200)->nullable();
            $table->string('rera_number', 50)->nullable();
            $table->integer('no_of_employees')->nullable();

            $table->unsignedBigInteger('purpose_id')->nullable();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->unsignedBigInteger('property_type_id')->nullable();
            $table->text('about_us')->nullable();
            $table->string('aadhaar_number', 20)
                  ->nullable()
                  ->unique()
                  ->comment('Aadhaar card number - unique per user');

            $table->string('aadhaar_front')
                  ->nullable()
                  ->comment('Uploaded Aadhaar front image');

            
            $table->string('aadhaar_back')
                  ->nullable()
                  ->comment('Uploaded Aadhaar back image');

            $table->string('business_proof')
                  ->nullable()
                  ->comment('Business proof document');
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_details');
    }
};
