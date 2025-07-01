<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('top_features', function (Blueprint $table) {
            $table->id();
            // $table->enum('featured_type', [
            //     'home_page',
            //     'single_user_details',
            //     'single_property_details',
            //     'single_project_details',
            //     'signle_developer_details',
            //     'search_project_result',
            //     'search_property_result',
            //     'search_developer_result',
            //     'search_user_detials'
            // ]);
            $table->json('featured_type');
            $table->enum('status', ['1', '0'])->default('1');
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('top_features');
    }
};
