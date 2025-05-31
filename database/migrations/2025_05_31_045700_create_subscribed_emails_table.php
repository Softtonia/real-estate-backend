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
        Schema::create('subscribed_emails', function (Blueprint $table) {
            $table->id();
            $table->string('subscribe_email', 255)->nullable();

            $table->boolean('is_subscribed')->default(0);
            $table->string('user_id', 255)->nullable();
            $table->enum('custom_tag', ['New Subscribers', 'Promo Campaign'])->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscribed_emails');
    }
};
