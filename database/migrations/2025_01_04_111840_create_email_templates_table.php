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
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id(); // id (PRIMARY KEY, AUTO_INCREMENT)
            $table->string('name', 255)->notNullable(); // Name of the email template
            $table->string('subject', 255)->notNullable(); // Subject line of the email template
            $table->text('body')->notNullable(); // HTML or plain text content of the email
            // $table->enum('template_type', ['Welcome', 'Follow-Up', 'Reminder', 'Promotion', 'Other'])
            //     ->default('Other'); // Type/category of the email template
            $table->string('template_type')->default('Other');
            $table->enum('status', ['Active', 'Inactive'])->default('Active'); // Status of the template
            $table->timestamps(); // created_at and updated_at
            $table->unsignedBigInteger('created_by')->nullable(); // User ID of the creator
            $table->unsignedBigInteger('updated_by')->nullable(); // User ID of the last updater
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
