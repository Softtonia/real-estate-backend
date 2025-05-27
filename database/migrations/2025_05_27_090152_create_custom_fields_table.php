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
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('field_label', 255)->collation('utf8mb4_unicode_ci')->nullable();
            $table->string('field_name_slug', 255)->collation('utf8mb4_unicode_ci')->nullable()->comment('this is field key');
            $table->string('field_placeholder', 255)->collation('utf8mb4_unicode_ci')->nullable();
            $table->enum('field_type', ['text', 'textarea', 'texteditor', 'number', 'file', 'checkbox', 'select', 'radio', 'url', 'repeater','media'])->collation('utf8mb4_unicode_ci');
            $table->enum('post_type', ['project', 'property_list', 'developer_list'])->default('property_list')->collation('utf8mb4_unicode_ci');
            $table->enum('required', ['yes', 'no'])->collation('utf8mb4_unicode_ci');
            $table->string('media_limit', 20)->collation('utf8mb4_unicode_ci')->nullable();
            $table->string('media_size', 20)->collation('utf8mb4_unicode_ci')->nullable();
            $table->string('media_format', 100)->collation('utf8mb4_unicode_ci')->nullable();
            $table->string('checkbox_type', 50)->collation('utf8mb4_unicode_ci')->nullable();
            $table->string('select_type', 255)->collation('utf8mb4_unicode_ci')->nullable();
            $table->longText('model_fields')->collation('utf8mb4_bin')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};
