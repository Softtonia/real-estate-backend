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
        Schema::create('developer_listings', function (Blueprint $table) {
            $table->id();
            $table->enum('live_status', ['Approve', 'Disapprove', 'Reject', 'Under Review', 'Modify Review'])->default('Under Review')
                ->collation('utf8mb4_general_ci');
            $table->enum('temporary_status', ['active', 'deactive'])->default('active')
                ->collation('utf8mb4_general_ci');

            $table->string('developer_unique_id', 30)->collation('utf8mb4_general_ci')->nullable();
            $table->string('name', 100)->collation('utf8mb4_general_ci')->nullable();
            $table->longText('description')->collation('utf8mb4_general_ci')->nullable();

            $table->unsignedBigInteger('purpose_id')->nullable();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->json('property_type_id')->nullable();
            $table->json('property_status_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();



            $table->string('status_reason', 100)->collation('utf8mb4_general_ci')->nullable();

            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();

            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();

            $table->string('address', 255)->collation('utf8mb4_general_ci')->nullable();
            $table->string('featured_image', 255)->collation('utf8mb4_general_ci')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('developer_listings');
    }
};
