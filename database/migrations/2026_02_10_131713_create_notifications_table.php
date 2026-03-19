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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

              // Utilisateur qui reçoit la notification
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Ticket concerné (peut être null si notification générale)
            $table->foreignId('ticket_id')->nullable()
                ->constrained('tickets')
                ->cascadeOnDelete();

            // Type d'événement
            $table->enum('type', ['NEW_TICKET', 'NEW_MESSAGE', 'TICKET_UPDATED', 'STATUS_CHANGED']);

            // Message descriptif
            $table->text('contenu');

            // Notification lue ou non
            $table->boolean('lu')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
