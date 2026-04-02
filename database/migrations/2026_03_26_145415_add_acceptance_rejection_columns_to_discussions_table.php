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
        Schema::table('discussions', function (Blueprint $table) {
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('accepted_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->text('rejection_reason')->nullable();

            // Clés étrangères
            $table->foreign('accepted_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('rejected_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discussions', function (Blueprint $table) {
             $table->dropForeign(['accepted_by']);
            $table->dropForeign(['rejected_by']);
            $table->dropColumn(['accepted_at', 'accepted_by', 'rejected_at', 'rejected_by', 'rejection_reason']);
        });
    }
};
