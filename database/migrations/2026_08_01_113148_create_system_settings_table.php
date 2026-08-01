<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('system_settings')) {
            return;
        }

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();

            $table->string('group')->index();
            $table->string('key')->unique();

            $table->longText('value')->nullable();
            $table->string('value_type', 50)->default('string');

            $table->boolean('is_encrypted')->default(false);
            $table->boolean('status')->default(true);

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();

            $table->timestamps();

            $table->index(['group', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};