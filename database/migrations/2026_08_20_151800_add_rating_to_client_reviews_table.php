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
        if (Schema::hasTable('client_reviews') && !Schema::hasColumn('client_reviews', 'rating')) {
            Schema::table('client_reviews', function (Blueprint $table) {
                $table->unsignedTinyInteger('rating')->default(5)->nullable()->after('review');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('client_reviews') && Schema::hasColumn('client_reviews', 'rating')) {
            Schema::table('client_reviews', function (Blueprint $table) {
                $table->dropColumn('rating');
            });
        }
    }
};
