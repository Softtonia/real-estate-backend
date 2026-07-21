<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dynamic_post_form_step_fields', function (Blueprint $table) {
            if (!Schema::hasColumn('dynamic_post_form_step_fields', 'field_width')) {
                $table->unsignedTinyInteger('field_width')
                    ->default(100)
                    ->after('sort_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dynamic_post_form_step_fields', function (Blueprint $table) {
            if (Schema::hasColumn('dynamic_post_form_step_fields', 'field_width')) {
                $table->dropColumn('field_width');
            }
        });
    }
};