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
        Schema::table('tickets', function (Blueprint $table) {
            // Drop foreign key constraint if it exists
            // $table->dropForeign(['ticket_type_id']);

            // Remove the ticket_type_id field
            // $table->dropColumn('ticket_type_id');

            // Add the new ticket_department_id field
            $table->integer('ticket_department_id');

            // Add foreign key constraint for ticket_department_id
            // $table->foreign('ticket_department_id')->references('id')->on('ticket_departments')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Rollback: Remove ticket_department_id and re-add ticket_type_id
            // $table->dropForeign(['ticket_department_id']);
            $table->dropColumn('ticket_department_id');

            // Re-add ticket_type_id
            // $table->unsignedBigInteger('ticket_type_id')->after('user_id');

            // Re-add foreign key for ticket_type_id (assuming it referenced 'ticket_types' table)
            // $table->foreign('ticket_type_id')->references('id')->on('ticket_types')->onDelete('cascade');
        });
    }
};
