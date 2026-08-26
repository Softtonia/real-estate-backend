<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_recently_viewed_posts')) {
            Schema::create('user_recently_viewed_posts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('guest_session_id', 100)->nullable()->index();
                $table->unsignedBigInteger('dynamic_post_id')->index();
                $table->unsignedBigInteger('post_type_id')->nullable()->index();
                $table->unsignedInteger('view_count')->default(1);
                $table->timestamp('viewed_at')->useCurrent()->index();
                $table->timestamps();

                $table->foreign('dynamic_post_id')
                    ->references('id')
                    ->on('dynamic_posts')
                    ->onDelete('cascade');

                $table->index(['user_id', 'viewed_at']);
                $table->index(['guest_session_id', 'viewed_at']);
                $table->index(['user_id', 'post_type_id']);
                $table->index(['guest_session_id', 'post_type_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_recently_viewed_posts');
    }
};
