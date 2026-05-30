<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->index(['tokenable_id', 'tokenable_type', 'created_at'], 'tokenable_created_idx');
            $table->index(['token', 'last_used_at'], 'token_last_used_idx');
            $table->index('last_used_at');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex('tokenable_created_idx');
            $table->dropIndex('token_last_used_idx');
            $table->dropIndex(['last_used_at']);
            $table->dropIndex(['expires_at']);
        });
    }
};