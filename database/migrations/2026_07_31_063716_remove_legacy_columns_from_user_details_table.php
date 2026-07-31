<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $legacyColumns = [
        'aadhaar_number',
        'aadhaar_front',
        'aadhaar_back',
        'business_proof',
        'purpose_id',
        'property_id',
        'property_type_id',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('user_details')) {
            return;
        }

        /*
         * purpose_id, property_id ya property_type_id par foreign key ho
         * to column delete karne se pehle foreign key remove karo.
         */
        foreach ($this->legacyColumns as $column) {
            if (!Schema::hasColumn('user_details', $column)) {
                continue;
            }

            $foreignKeys = DB::table(
                'information_schema.KEY_COLUMN_USAGE'
            )
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'user_details')
                ->where('COLUMN_NAME', $column)
                ->whereNotNull('REFERENCED_TABLE_NAME')
                ->pluck('CONSTRAINT_NAME');

            foreach ($foreignKeys as $foreignKey) {
                $safeForeignKey = str_replace(
                    '`',
                    '``',
                    (string) $foreignKey
                );

                DB::statement(
                    "ALTER TABLE `user_details`
                     DROP FOREIGN KEY `{$safeForeignKey}`"
                );
            }
        }

        $existingColumns = collect($this->legacyColumns)
            ->filter(
                fn (string $column): bool =>
                    Schema::hasColumn('user_details', $column)
            )
            ->values()
            ->all();

        if (empty($existingColumns)) {
            return;
        }

        Schema::table(
            'user_details',
            function (Blueprint $table) use ($existingColumns) {
                $table->dropColumn($existingColumns);
            }
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_details')) {
            return;
        }

        $missingColumns = collect($this->legacyColumns)
            ->reject(
                fn (string $column): bool =>
                    Schema::hasColumn('user_details', $column)
            )
            ->values()
            ->all();

        if (empty($missingColumns)) {
            return;
        }

        Schema::table(
            'user_details',
            function (Blueprint $table) use ($missingColumns) {
                foreach ($missingColumns as $column) {
                    match ($column) {
                        'aadhaar_number' =>
                            $table->string(
                                'aadhaar_number',
                                12
                            )->nullable(),

                        'aadhaar_front',
                        'aadhaar_back',
                        'business_proof' =>
                            $table->text($column)->nullable(),

                        'purpose_id',
                        'property_id',
                        'property_type_id' =>
                            $table->unsignedBigInteger(
                                $column
                            )->nullable(),

                        default => null,
                    };
                }
            }
        );
    }
};