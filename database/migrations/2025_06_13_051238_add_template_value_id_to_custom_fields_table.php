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
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->unsignedBigInteger('template_value_id')->nullable()->after('id');

            $table->foreign('template_value_id')
                ->references('id')
                ->on('template_value_id')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropForeign(['template_value_id']);
            $table->dropColumn('template_value_id');
        });
    }
};
