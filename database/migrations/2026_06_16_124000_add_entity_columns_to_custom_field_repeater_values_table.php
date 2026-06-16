<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_field_repeater_values', function (Blueprint $table) {
            $table->string('entity_type')->nullable()->after('custom_repeater_value_id');
            $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');

            $table->index(['entity_type', 'entity_id']);
            $table->index('custom_field_id');
            $table->index('unique_id');
        });
    }

    public function down(): void
    {
        Schema::table('custom_field_repeater_values', function (Blueprint $table) {
            $table->dropIndex(['entity_type', 'entity_id']);
            $table->dropColumn(['entity_type', 'entity_id']);
        });
    }
};