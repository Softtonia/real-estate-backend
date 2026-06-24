<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            if (!Schema::hasColumn('templates', 'template_type')) {
                $table->enum('template_type', ['single_post', 'page', 'section'])
                    ->default('single_post')
                    ->after('id');
            }

            if (!Schema::hasColumn('templates', 'template_name')) {
                $table->string('template_name')->after('template_type');
            }

            if (!Schema::hasColumn('templates', 'slug')) {
                $table->string('slug')->nullable()->unique()->after('template_name');
            }

            if (!Schema::hasColumn('templates', 'shortcode')) {
                $table->string('shortcode')->nullable()->unique()->after('slug');
            }

            if (!Schema::hasColumn('templates', 'priority')) {
                $table->integer('priority')->default(0)->after('shortcode');
            }

            if (!Schema::hasColumn('templates', 'status')) {
                $table->enum('status', ['active', 'inactive', 'draft'])
                    ->default('active')
                    ->after('priority');
            }
        });

        Schema::table('template_display_conditions', function (Blueprint $table) {
            if (!Schema::hasColumn('template_display_conditions', 'source_type')) {
                $table->enum('source_type', ['post_type', 'taxonomy'])
                    ->default('post_type')
                    ->after('show_type');
            }

            if (!Schema::hasColumn('template_display_conditions', 'post_type_slug')) {
                $table->string('post_type_slug')->nullable()->after('source_type');
            }

            if (!Schema::hasColumn('template_display_conditions', 'taxonomy_slug')) {
                $table->string('taxonomy_slug')->nullable()->after('post_type_slug');
            }

            if (!Schema::hasColumn('template_display_conditions', 'taxonomy_term_ids')) {
                $table->json('taxonomy_term_ids')->nullable()->after('taxonomy_slug');
            }

            if (!Schema::hasColumn('template_display_conditions', 'relation')) {
                $table->enum('relation', ['and', 'or'])->default('and')->after('taxonomy_term_ids');
            }
        });
    }

    public function down(): void
    {
        Schema::table('template_display_conditions', function (Blueprint $table) {
            $table->dropColumn([
                'source_type',
                'post_type_slug',
                'taxonomy_slug',
                'taxonomy_term_ids',
                'relation',
            ]);
        });

        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn([
                'template_type',
                'shortcode',
            ]);
        });
    }
};