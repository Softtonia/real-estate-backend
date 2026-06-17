<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_field_group_location_rules', function (Blueprint $table) {
            if (!Schema::hasColumn('custom_field_group_location_rules', 'logic_operator')) {
                $table->string('logic_operator', 10)->nullable()->after('custom_field_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('custom_field_group_location_rules', function (Blueprint $table) {
            if (Schema::hasColumn('custom_field_group_location_rules', 'logic_operator')) {
                $table->dropColumn('logic_operator');
            }
        });
    }
};