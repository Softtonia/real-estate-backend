<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create user_personal_details table
        if (!Schema::hasTable('user_personal_details')) {
            Schema::create('user_personal_details', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();

                $table->string('alternate_number', 200)->nullable();
                $table->string('profile_photo', 255)->nullable();
                $table->text('about_us')->nullable();

                $table->unsignedBigInteger('country_id')->nullable()->index();
                $table->unsignedBigInteger('state_id')->nullable()->index();
                $table->unsignedBigInteger('city_id')->nullable()->index();

                $table->string('area_locality', 255)->nullable();
                $table->string('colony', 255)->nullable();
                $table->string('street_address', 255)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('pin_code', 20)->nullable();

                $table->unsignedBigInteger('created_by')->default(0);
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('country_id')->references('id')->on('countries')->onDelete('set null');
                $table->foreign('state_id')->references('id')->on('states')->onDelete('set null');
                $table->foreign('city_id')->references('id')->on('cities')->onDelete('set null');
            });
        }

        // 2. Create user_business_details table
        if (!Schema::hasTable('user_business_details')) {
            Schema::create('user_business_details', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();

                $table->string('business_name', 255)->nullable();
                $table->string('business_phone', 200)->nullable();
                $table->string('business_email', 255)->nullable();
                $table->text('business_address')->nullable();

                $table->unsignedBigInteger('country_id')->nullable()->index();
                $table->unsignedBigInteger('state_id')->nullable()->index();
                $table->unsignedBigInteger('city_id')->nullable()->index();

                $table->string('area_locality', 255)->nullable();
                $table->string('colony', 255)->nullable();
                $table->string('street_address', 255)->nullable();
                $table->string('business_pin_code', 20)->nullable();

                $table->string('license_number', 200)->nullable();
                $table->string('rera_number', 50)->nullable();
                $table->integer('no_of_employees')->nullable();
                $table->text('about_business')->nullable();

                $table->unsignedBigInteger('created_by')->default(0);
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('country_id')->references('id')->on('countries')->onDelete('set null');
                $table->foreign('state_id')->references('id')->on('states')->onDelete('set null');
                $table->foreign('city_id')->references('id')->on('cities')->onDelete('set null');
            });
        }

        // 3. Migrate existing data from user_details / users into new tables
        if (Schema::hasTable('user_details')) {
            $existingDetails = DB::table('user_details')->get();

            foreach ($existingDetails as $detail) {
                if (empty($detail->user_id)) {
                    continue;
                }

                $user = DB::table('users')->where('id', $detail->user_id)->first();

                // Skip orphaned records if user no longer exists in users table
                if (!$user) {
                    continue;
                }

                // Personal details insert/update
                DB::table('user_personal_details')->updateOrInsert(
                    ['user_id' => $detail->user_id],
                    [
                        'alternate_number' => $detail->alternate_number ?? null,
                        'profile_photo' => $detail->profile_photo ?? null,
                        'about_us' => $detail->about_us ?? ($user->about ?? null),
                        'country_id' => $user->country_id ?? ($detail->country_id ?? null),
                        'state_id' => $user->state_id ?? ($detail->state_id ?? null),
                        'city_id' => $user->city_id ?? ($detail->city_id ?? null),
                        'area_locality' => $user->area_locality ?? ($detail->area_locality ?? null),
                        'colony' => $user->colony ?? ($detail->colony ?? null),
                        'street_address' => $user->street_address ?? ($detail->street_address ?? null),
                        'address' => $detail->address ?? null,
                        'pin_code' => $user->pin_code ?? ($detail->pin_code ?? null),
                        'created_by' => $detail->created_by ?? ($user->created_by ?? 0),
                        'created_at' => $detail->created_at ?? now(),
                        'updated_at' => $detail->updated_at ?? now(),
                    ]
                );

                // Business details insert/update (if business info exists)
                $bName = $detail->bussiness_name ?? null;
                $bPhone = $detail->business_phone ?? null;
                $bEmail = $detail->bussiness_email ?? null;
                $bAddress = $detail->bussiness_address ?? null;
                $bPinCode = $detail->pin_code ?? ($user->pin_code ?? null);

                if ($bName || $bPhone || $bEmail || $bAddress || !empty($detail->license_number) || !empty($detail->rera_number)) {
                    DB::table('user_business_details')->updateOrInsert(
                        ['user_id' => $detail->user_id],
                        [
                            'business_name' => $bName,
                            'business_phone' => $bPhone,
                            'business_email' => $bEmail,
                            'business_address' => $bAddress,
                            'country_id' => $detail->country_id ?? null,
                            'state_id' => $detail->state_id ?? null,
                            'city_id' => $detail->city_id ?? null,
                            'area_locality' => $detail->area_locality ?? null,
                            'colony' => $detail->colony ?? null,
                            'street_address' => $detail->street_address ?? null,
                            'business_pin_code' => $bPinCode,
                            'license_number' => $detail->license_number ?? null,
                            'rera_number' => $detail->rera_number ?? null,
                            'no_of_employees' => $detail->no_of_employees ?? null,
                            'about_business' => $detail->about_us ?? null,
                            'created_by' => $detail->created_by ?? 0,
                            'created_at' => $detail->created_at ?? now(),
                            'updated_at' => $detail->updated_at ?? now(),
                        ]
                    );
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_business_details');
        Schema::dropIfExists('user_personal_details');
    }
};
