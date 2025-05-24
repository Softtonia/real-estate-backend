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
        Schema::table('subscribed_emails', function (Blueprint $table) {
            $table->boolean('is_subscribed')->default(false);
            $table->unsignedBigInteger('user_id')->nullable(); // Remove auto_increment
            $table->enum('custom_tag', ['New Subscribers', 'Promo Campaign'])->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscribed_emails', function (Blueprint $table) {
            $table->dropColumn(['is_subscribed', 'user_id', 'custom_tag']);
        });
    }
};
