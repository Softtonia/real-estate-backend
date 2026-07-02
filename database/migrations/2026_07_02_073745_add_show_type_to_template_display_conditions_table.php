<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('template_display_conditions', 'show_type')) {
            Schema::table('template_display_conditions', function (Blueprint $table) {
                $table->string('show_type')->default('include')->after('template_id');
            });
        }

        if (Schema::hasColumn('template_display_conditions', 'show_if')) {
            DB::table('template_display_conditions')->update([
                'show_type' => DB::raw('show_if'),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('template_display_conditions', 'show_type')) {
            Schema::table('template_display_conditions', function (Blueprint $table) {
                $table->dropColumn('show_type');
            });
        }
    }
};