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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
             // Classification
            $table->string('type');
            $table->string('categorie');
            $table->string('statut');
            $table->string('urgence');
    // Temps
            $table->timestamp('date_ouverture')->useCurrent();
            $table->integer('sla_ttr')->nullable();
    // Demandeur
            $table->string('source_demande');
            $table->string('prenom');
            $table->string('nom');
            $table->string('email');
            $table->string('adresse')->nullable();
    // Détails
            $table->text('detail');
            // Relations
            $table->foreignId('user_id') // créateur
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('observateur_id')->nullable()
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->foreignId('entite_id')
                ->constrained('entites')
                ->cascadeOnDelete();

            $table->foreignId('element_id')
                ->constrained('elements')
                ->cascadeOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
