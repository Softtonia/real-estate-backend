<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('menus')) {
            Schema::table('menus', function (Blueprint $table) {
                if (!Schema::hasColumn('menus', 'menu_name')) {
                    $table->string('menu_name', 191)->nullable()->after('location');
                }
                if (!Schema::hasColumn('menus', 'css_class')) {
                    $table->string('css_class', 191)->nullable()->after('url');
                }
                if (!Schema::hasColumn('menus', 'icon')) {
                    $table->string('icon', 191)->nullable()->after('css_class');
                }
                if (!Schema::hasColumn('menus', 'badge')) {
                    $table->string('badge', 100)->nullable()->after('icon');
                }
                if (!Schema::hasColumn('menus', 'depth')) {
                    $table->unsignedSmallInteger('depth')->default(0)->after('position');
                }
            });

            // Ensure entity_type, location, and link_type support flexible string values
            try {
                Schema::table('menus', function (Blueprint $table) {
                    $table->string('entity_type', 100)->nullable()->default('custom')->change();
                    $table->string('location', 100)->nullable()->default('Header')->change();
                    $table->string('link_type', 100)->nullable()->default('url')->change();
                    $table->string('menu_type', 100)->nullable()->default('normal')->change();
                });
            } catch (\Throwable $e) {
                // Ignore if DB driver doesn't support enum change without doctrine/dbal
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Schema::table('menus', function (Blueprint $table) {
                if (Schema::hasColumn('menus', 'menu_name')) {
                    $table->dropColumn('menu_name');
                }
                if (Schema::hasColumn('menus', 'css_class')) {
                    $table->dropColumn('css_class');
                }
                if (Schema::hasColumn('menus', 'icon')) {
                    $table->dropColumn('icon');
                }
                if (Schema::hasColumn('menus', 'badge')) {
                    $table->dropColumn('badge');
                }
                if (Schema::hasColumn('menus', 'depth')) {
                    $table->dropColumn('depth');
                }
            });
        }
    }
};
