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
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_prefix', 10)->default('TCKT');
            $table->string('property_prefix', 10)->default('URPL');
            $table->string('developer_prefix', 10)->default('URPD');
            $table->string('project_prefix', 10)->default('URPP');
            $table->string('website_logo', 50)->nullable();
            $table->string('mobile_logo', 50)->nullable();
            $table->string('favicon', 50)->nullable();
            $table->string('site_name', 100)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('mobile_number', 15)->nullable();
            $table->string('for_general_mobile_number',15)->nullable();
            $table->string('for_sales_mobile_number',15)->nullable();
            $table->string('for_business_mobile_number',15)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('copyright_text', 255)->nullable();
            $table->longText('disclaimer')->nullable();
            $table->longText('site_short_description')->nullable();
            $table->longText('subscribe_short_description')->nullable();
            $table->string('facebook', 100)->nullable();
            $table->string('instagram', 100)->nullable();
            $table->string('twitter', 100)->nullable();
            $table->timestamp('created_at')->nullable()->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable()->default(DB::raw('CURRENT_TIMESTAMP'));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
