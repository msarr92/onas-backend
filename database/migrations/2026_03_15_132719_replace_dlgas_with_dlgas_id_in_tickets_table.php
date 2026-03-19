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
              // supprimer ancien champ
            $table->dropColumn('dlgas');

            // ajouter la relation avec users
            $table->foreignId('dlgas_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
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

            $table->string('dlgas')->nullable();
        });
    }
};
