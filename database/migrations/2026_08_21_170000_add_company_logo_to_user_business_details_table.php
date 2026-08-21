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
        if (Schema::hasTable('user_business_details') && !Schema::hasColumn('user_business_details', 'company_logo')) {
            Schema::table('user_business_details', function (Blueprint $table) {
                $table->string('company_logo', 255)->nullable()->after('business_name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('user_business_details') && Schema::hasColumn('user_business_details', 'company_logo')) {
            Schema::table('user_business_details', function (Blueprint $table) {
                $table->dropColumn('company_logo');
            });
        }
    }
};
