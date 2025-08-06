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
        Schema::table('users', function (Blueprint $table) {
          $table->unsignedBigInteger('country_id')->nullable()->after('phone');
            $table->unsignedBigInteger('state_id')->nullable()->after('country_id');
            $table->unsignedBigInteger('city_id')->nullable()->after('state_id');

            $table->string('area')->nullable()->after('city_id');
            $table->string('locality')->nullable()->after('area');
            $table->string('colony')->nullable()->after('locality');
            $table->string('street_address')->nullable()->after('colony');
            $table->string('pin_code', 10)->nullable()->after('street_address');
            $table->text('about')->nullable()->after('pin_code');

            $table->foreign('country_id')->references('id')->on('countries')->onDelete('cascade');
            $table->foreign('state_id')->references('id')->on('states')->onDelete('cascade');
            $table->foreign('city_id')->references('id')->on('cities')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['country_id']);
            $table->dropForeign(['state_id']);
            $table->dropForeign(['city_id']);

            $table->dropColumn([
                'country_id', 'state_id', 'city_id', 'area', 'locality', 'colony',
                'street_address', 'pincode', 'about'
            ]);
        });
    }
};
