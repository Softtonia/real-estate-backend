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
            $table->unsignedBigInteger('top_featured_id')->nullable()->after('id');
            $table->foreign('top_featured_id')->references('id')->on('top_features')->onDelete('set null');
        });

        Schema::table('properties_listing', function (Blueprint $table) {
            $table->unsignedBigInteger('top_featured_id')->nullable()->after('id');
            $table->foreign('top_featured_id')->references('id')->on('top_features')->onDelete('set null');
        });

        Schema::table('developer_listings', function (Blueprint $table) {
            $table->unsignedBigInteger('top_featured_id')->nullable()->after('id');
            $table->foreign('top_featured_id')->references('id')->on('top_features')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_listings', function (Blueprint $table) {
            $table->dropForeign(['top_featured_id']);
            $table->dropColumn('top_featured_id');
        });

        Schema::table('properties_listing', function (Blueprint $table) {
            $table->dropForeign(['top_featured_id']);
            $table->dropColumn('top_featured_id');
        });

        Schema::table('developer_listings', function (Blueprint $table) {
            $table->dropForeign(['top_featured_id']);
            $table->dropColumn('top_featured_id');
        });
    }
};
