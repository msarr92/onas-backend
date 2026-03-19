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
              // Ajout de la colonne dlgas_id
            $table->foreignId('dlgas_id')
                ->nullable()          // Permet que certains tickets n'aient pas de DLGA assigné
                ->constrained('users') // Clé étrangère vers users.id
                ->nullOnDelete();       // Si l'utilisateur est supprimé, la colonne devient NULL

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
             $table->dropForeign(['dlgas_id']);
            $table->dropColumn('dlgas_id');
        });
    }
};
