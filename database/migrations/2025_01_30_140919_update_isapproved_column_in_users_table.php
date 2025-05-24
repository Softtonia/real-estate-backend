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
        Schema::table('users', function (Blueprint $table) {
            $table->tinyInteger('isapproved')->default(1)->comment('Active=1, Deactive=2, UnderReview=3, Reject=4')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->tinyInteger('isapproved')->default(1)->comment('Active=1, Deactive=2, UnderReview=3')->change();
        });
    }
};
