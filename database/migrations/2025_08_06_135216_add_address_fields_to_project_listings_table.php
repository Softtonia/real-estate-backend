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
        Schema::table('project_listings', function (Blueprint $table) {
            $table->string('area_locality')->nullable()->after('city_id');
            $table->string('colony')->nullable()->after('area_locality');
            $table->string('street_address')->nullable()->after('colony');
            $table->string('pin_code', 10)->nullable()->after('street_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_listings', function (Blueprint $table) {
             $table->dropColumn([
                'area_locality',

                'colony',
                'street_address','pin_code'
            ]);
        });
    }
};
