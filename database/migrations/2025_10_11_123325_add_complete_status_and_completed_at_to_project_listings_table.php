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
        Schema::table('project_listings', function (Blueprint $table) {
            $table->boolean('complete_status')->default(false)->after('featured_image');
            $table->timestamp('completed_at')->nullable()->after('complete_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_listings', function (Blueprint $table) {
            $table->dropColumn(['complete_status', 'completed_at']);
        });
    }
};
