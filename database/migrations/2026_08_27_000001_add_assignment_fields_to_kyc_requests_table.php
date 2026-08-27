<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('kyc_requests', 'assigned_to')) {
                $table->foreignId('assigned_to')
                    ->nullable()
                    ->after('reviewed_by')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('kyc_requests', 'assigned_by')) {
                $table->foreignId('assigned_by')
                    ->nullable()
                    ->after('assigned_to')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('kyc_requests', 'assigned_at')) {
                $table->timestamp('assigned_at')
                    ->nullable()
                    ->after('assigned_by');
            }

            if (!Schema::hasColumn('kyc_requests', 'assign_notes')) {
                $table->text('assign_notes')
                    ->nullable()
                    ->after('assigned_at');
            }

            // Indexes
            $table->index('assigned_to');
            $table->index('assigned_by');
            $table->index('assigned_at');
            $table->index(['status', 'assigned_to']);
        });
    }

    public function down(): void
    {
        Schema::table('kyc_requests', function (Blueprint $table) {
            if (Schema::hasColumn('kyc_requests', 'assigned_to')) {
                $table->dropForeign(['assigned_to']);
                $table->dropColumn('assigned_to');
            }

            if (Schema::hasColumn('kyc_requests', 'assigned_by')) {
                $table->dropForeign(['assigned_by']);
                $table->dropColumn('assigned_by');
            }

            if (Schema::hasColumn('kyc_requests', 'assigned_at')) {
                $table->dropColumn('assigned_at');
            }

            if (Schema::hasColumn('kyc_requests', 'assign_notes')) {
                $table->dropColumn('assign_notes');
            }
        });
    }
};
