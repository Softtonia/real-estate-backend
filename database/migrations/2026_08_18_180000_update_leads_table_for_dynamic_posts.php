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
        if (!Schema::hasTable('leads')) {
            Schema::create('leads', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255);
                $table->string('email', 255);
                $table->string('phone', 50);
                $table->text('message')->nullable();
                $table->foreignId('lead_type_id')->nullable()->constrained('lead_types')->nullOnDelete();
                $table->foreignId('dynamic_post_id')->nullable()->constrained('dynamic_posts')->nullOnDelete();
                $table->foreignId('post_type_id')->nullable()->constrained('post_types')->nullOnDelete();
                $table->json('user_ids')->nullable();
                $table->foreignId('created_by_admin')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        } else {
            Schema::table('leads', function (Blueprint $table) {
                if (!Schema::hasColumn('leads', 'dynamic_post_id')) {
                    $table->foreignId('dynamic_post_id')->nullable()->after('lead_type_id')->constrained('dynamic_posts')->nullOnDelete();
                }
                if (!Schema::hasColumn('leads', 'post_type_id')) {
                    $table->foreignId('post_type_id')->nullable()->after('dynamic_post_id')->constrained('post_types')->nullOnDelete();
                }
                if (!Schema::hasColumn('leads', 'created_by_admin')) {
                    $table->foreignId('created_by_admin')->nullable()->after('user_ids')->constrained('users')->nullOnDelete();
                }

                // Drop legacy hardcoded columns if present
                if (Schema::hasColumn('leads', 'property_id')) {
                    $table->dropColumn('property_id');
                }
                if (Schema::hasColumn('leads', 'project_id')) {
                    $table->dropColumn('project_id');
                }
                if (Schema::hasColumn('leads', 'developer_id')) {
                    $table->dropColumn('developer_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('leads')) {
            Schema::table('leads', function (Blueprint $table) {
                if (Schema::hasColumn('leads', 'created_by_admin')) {
                    $table->dropForeign(['created_by_admin']);
                    $table->dropColumn('created_by_admin');
                }
                if (Schema::hasColumn('leads', 'post_type_id')) {
                    $table->dropForeign(['post_type_id']);
                    $table->dropColumn('post_type_id');
                }
                if (Schema::hasColumn('leads', 'dynamic_post_id')) {
                    $table->dropForeign(['dynamic_post_id']);
                    $table->dropColumn('dynamic_post_id');
                }
            });
        }
    }
};
