<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_details')) {
            /*
             * Drop old Aadhaar unique index safely.
             * Index may or may not exist depending on production DB state.
             */
            try {
                DB::statement('ALTER TABLE user_details DROP INDEX user_details_aadhaar_number_unique');
            } catch (Throwable $e) {
                // Ignore if index does not exist.
            }

            $userDetailColumns = [
                'aadhaar_number',
                'aadhaar_front',
                'aadhaar_back',
                'business_proof',
                'license_number',
                'rera_number',
            ];

            $existingColumns = array_values(array_filter(
                $userDetailColumns,
                fn (string $column) => Schema::hasColumn('user_details', $column)
            ));

            if (!empty($existingColumns)) {
                Schema::table('user_details', function (Blueprint $table) use ($existingColumns) {
                    $table->dropColumn($existingColumns);
                });
            }
        }

        /*
         * Optional:
         * Drop users.kyc only if your full project now uses kyc_requests.status.
         * Keep it if middleware/listing publish logic still checks users.kyc.
         */
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'kyc')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('kyc');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'kyc')) {
            Schema::table('users', function (Blueprint $table) {
                $table->integer('kyc')
                    ->default(0)
                    ->comment('0 = Kyc Pending, 1 = Kyc In Progress, 2 = Kyc Approved, 3 = Kyc Rejected');
            });
        }

        if (Schema::hasTable('user_details')) {
            Schema::table('user_details', function (Blueprint $table) {
                if (!Schema::hasColumn('user_details', 'license_number')) {
                    $table->string('license_number', 200)->nullable();
                }

                if (!Schema::hasColumn('user_details', 'rera_number')) {
                    $table->string('rera_number', 50)->nullable();
                }

                if (!Schema::hasColumn('user_details', 'aadhaar_number')) {
                    $table->string('aadhaar_number', 20)
                        ->nullable()
                        ->unique()
                        ->comment('Aadhaar card number - unique per user');
                }

                if (!Schema::hasColumn('user_details', 'aadhaar_front')) {
                    $table->string('aadhaar_front')
                        ->nullable()
                        ->comment('Uploaded Aadhaar front image');
                }

                if (!Schema::hasColumn('user_details', 'aadhaar_back')) {
                    $table->string('aadhaar_back')
                        ->nullable()
                        ->comment('Uploaded Aadhaar back image');
                }

                if (!Schema::hasColumn('user_details', 'business_proof')) {
                    $table->string('business_proof')
                        ->nullable()
                        ->comment('Business proof document');
                }
            });
        }
    }
};