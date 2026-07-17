<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dynamic_posts', function (Blueprint $table) {
            if (!Schema::hasColumn('dynamic_posts', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('live_status');
            }

            if (!Schema::hasColumn('dynamic_posts', 'rejected_by')) {
                $table->foreignId('rejected_by')
                    ->nullable()
                    ->after('rejection_reason')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('dynamic_posts', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dynamic_posts', function (Blueprint $table) {
            if (Schema::hasColumn('dynamic_posts', 'rejected_by')) {
                $table->dropConstrainedForeignId('rejected_by');
            }

            if (Schema::hasColumn('dynamic_posts', 'rejected_at')) {
                $table->dropColumn('rejected_at');
            }

            if (Schema::hasColumn('dynamic_posts', 'rejection_reason')) {
                $table->dropColumn('rejection_reason');
            }
        });
    }
};