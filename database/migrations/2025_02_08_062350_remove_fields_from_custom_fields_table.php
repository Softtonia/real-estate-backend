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
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn([
                'created_by',
                'updated_by',
                'country_id',
                'state_id',
                'city_id',
                'live_status',
                'temporary_status'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
             // Add back columns in case of rollback
             $table->unsignedBigInteger('created_by')->nullable();
             $table->unsignedBigInteger('updated_by')->nullable();
             $table->unsignedBigInteger('country_id')->nullable();
             $table->unsignedBigInteger('state_id')->nullable();
             $table->unsignedBigInteger('city_id')->nullable();
             $table->enum('live_status', ['Approve', 'Disapprove', 'Reject', 'Under Review', 'Modify Review'])->default('Under Review');
             $table->enum('temporary_status', ['active', 'deactive'])->default('active');
        });
    }
};
