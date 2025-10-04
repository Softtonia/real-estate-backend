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
        Schema::create('project_listings', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('developer_id')->nullable();
            $table->string('project_unique_id', 30)->nullable();
            $table->string('name', 100)->nullable();
            $table->longText('description')->nullable();
            $table->integer('purpose_id')->nullable();
            $table->integer('property_id')->nullable();
            $table->json('property_type_id')->nullable();
            $table->json('property_status_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->string('status_reason', 100)->nullable();
            $table->enum('project_status', ['1', '0'])->default('1');
            $table->string('address', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('country_id')->nullable()->index();
            $table->unsignedBigInteger('state_id')->nullable()->index();
            $table->unsignedBigInteger('city_id')->nullable()->index();
            $table->enum('live_status', ['Approve', 'Disapprove', 'Reject', 'Under Review', 'Modify Review'])->default('Under Review');
            $table->enum('temporary_status', ['active', 'deactive'])->default('active');

            $table->foreign('country_id')->references('id')->on('countries')->onDelete('set null');
            $table->foreign('state_id')->references('id')->on('states')->onDelete('set null');
            $table->foreign('city_id')->references('id')->on('cities')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_listings');
    }
};
