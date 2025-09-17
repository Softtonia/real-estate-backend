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
        Schema::create('menus', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Basic fields
            $table->string('title', 191);
            $table->string('slug', 191)->nullable()->index();

            // Types & locations
            $table->enum('menu_type', ['normal', 'mega'])->default('normal')->index();
            $table->enum('location', ['Header', 'Footer', 'Sidebar'])->default('Header')->index();

            // Link handling
            $table->enum('link_type', ['none', 'url', 'query', 'entity'])->default('none');
            $table->string('url')->nullable();
            $table->enum('entity_type', ['property', 'category', 'agent', 'null'])->default('null');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('query_params')->nullable();

            // Mega menu specific settings
            $table->json('mega_settings')->nullable();

            // Structured data (JSON-LD) for menu items
            $table->json('structured_data')->nullable();

            // SEO
            $table->string('meta_title', 191)->nullable();
            $table->text('meta_description')->nullable();

            // Adjacency list for nesting
            $table->unsignedBigInteger('parent_id')->nullable()->index();

            // Ordering/visibility
            $table->unsignedInteger('position')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('open_in_new_tab')->default(false);

            // Auditing
            $table->unsignedBigInteger('created_by')->nullable()->index();

            // Timestamps & soft deletes
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('parent_id')
                  ->references('id')
                  ->on('menus')
                  ->onUpdate('cascade')
                  ->onDelete('set null');

            // If users table exists; keep nullable to avoid migration failure in uncommon setups.
            if (Schema::hasTable('users')) {
                $table->foreign('created_by')
                      ->references('id')
                      ->on('users')
                      ->onUpdate('cascade')
                      ->onDelete('set null');
            }
        });


        // Additional indexes that are sometimes added after table creation for clarity
        Schema::table('menus', function (Blueprint $table) {
            $table->index(['location', 'menu_type', 'is_active'], 'menus_location_type_active_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop foreign keys safely then drop table
        Schema::table('menus', function (Blueprint $table) {
            if (Schema::hasColumn('menus', 'parent_id')) {
                $table->dropForeign(['parent_id']);
            }
            if (Schema::hasColumn('menus', 'created_by') && Schema::hasTable('users')) {
                $table->dropForeign(['created_by']);
            }
        });

        Schema::dropIfExists('menus');
    }
};
