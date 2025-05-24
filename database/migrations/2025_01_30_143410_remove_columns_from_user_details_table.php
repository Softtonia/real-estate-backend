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
        Schema::table('user_details', function (Blueprint $table) {
            if (Schema::hasColumn('user_details', 'purpose_id')) {
                $table->dropColumn('purpose_id');
            }
            if (Schema::hasColumn('user_details', 'property_id')) {
                $table->dropColumn('property_id');
            }
            if (Schema::hasColumn('user_details', 'property_type_id')) {
                $table->dropColumn('property_type_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_details', function (Blueprint $table) {
            $table->unsignedBigInteger('purpose_id')->nullable()->after('some_column'); // Adjust position as needed
            $table->unsignedBigInteger('property_id')->nullable()->after('purpose_id');
            $table->unsignedBigInteger('property_type_id')->nullable()->after('property_id');
        });
    }
};
