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
    Schema::table('template_display_conditions', function (Blueprint $table) {
        $table->longText('condition_value')->nullable()->change();
    });
}

public function down(): void
{
    Schema::table('template_display_conditions', function (Blueprint $table) {
        $table->string('condition_value')->nullable()->change();
    });
}
};
