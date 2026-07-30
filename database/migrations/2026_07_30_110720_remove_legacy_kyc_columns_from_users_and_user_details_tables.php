<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_details')) {
            return;
        }

        $legacyColumns = collect([
            'aadhaar_number',
            'aadhaar_front',
            'aadhaar_back',
            'business_proof',
        ])->filter(
            fn (string $column): bool =>
                Schema::hasColumn('user_details', $column)
        )->values()->all();

        if (empty($legacyColumns)) {
            return;
        }

        Schema::table('user_details', function (Blueprint $table) use ($legacyColumns) {
            $table->dropColumn($legacyColumns);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_details')) {
            return;
        }

        Schema::table('user_details', function (Blueprint $table) {
            if (!Schema::hasColumn('user_details', 'aadhaar_number')) {
                $table->string('aadhaar_number', 12)->nullable();
            }

            if (!Schema::hasColumn('user_details', 'aadhaar_front')) {
                $table->text('aadhaar_front')->nullable();
            }

            if (!Schema::hasColumn('user_details', 'aadhaar_back')) {
                $table->text('aadhaar_back')->nullable();
            }

            if (!Schema::hasColumn('user_details', 'business_proof')) {
                $table->text('business_proof')->nullable();
            }
        });
    }
};