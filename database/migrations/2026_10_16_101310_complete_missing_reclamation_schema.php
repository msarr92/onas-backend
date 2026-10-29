<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1. TABLE USERS
        |--------------------------------------------------------------------------
        */

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'photo')) {
                $table->string('photo')->nullable();
            }

            if (!Schema::hasColumn('users', 'adresse')) {
                $table->text('adresse')->nullable();
            }
        });

        /*
        |--------------------------------------------------------------------------
        | 2. TABLE ENTITES
        |--------------------------------------------------------------------------
        */

        Schema::table('entites', function (Blueprint $table) {
            if (!Schema::hasColumn('entites', 'niveau')) {
                $table->unsignedTinyInteger('niveau')->default(1);
            }
        });

        /*
        |--------------------------------------------------------------------------
        | 3. TABLE ELEMENTS
        |--------------------------------------------------------------------------
        */

        Schema::table('elements', function (Blueprint $table) {
            if (!Schema::hasColumn('elements', 'description')) {
                $table->text('description')->nullable();
            }
        });

        /*
        |--------------------------------------------------------------------------
        | 4. TABLE TICKETS
        |--------------------------------------------------------------------------
        */

        Schema::table('tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('tickets', 'sous_entite_id')) {
                $table->foreignId('sous_entite_id')
                    ->nullable()
                    ->constrained('entites')
                    ->nullOnDelete();
            }
        });

        /*
        |--------------------------------------------------------------------------
        | 5. TABLE DISCUSSIONS
        | Les migrations précédentes prévoient déjà ces champs.
        | On vérifie leur présence pour éviter les doublons.
        |--------------------------------------------------------------------------
        */

        Schema::table('discussions', function (Blueprint $table) {
            if (!Schema::hasColumn('discussions', 'type')) {
                $table->string('type')->nullable();
            }

            if (!Schema::hasColumn('discussions', 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable();
            }

            if (!Schema::hasColumn('discussions', 'accepted_by')) {
                $table->unsignedBigInteger('accepted_by')->nullable();
            }

            if (!Schema::hasColumn('discussions', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable();
            }

            if (!Schema::hasColumn('discussions', 'rejected_by')) {
                $table->unsignedBigInteger('rejected_by')->nullable();
            }

            if (!Schema::hasColumn('discussions', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
        });

        /*
        |--------------------------------------------------------------------------
        | 6. TABLE TICKET LOCATIONS
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('ticket_locations')) {
            Schema::create('ticket_locations', function (Blueprint $table) {
                $table->id();

                $table->foreignId('ticket_id')
                    ->unique()
                    ->constrained('tickets')
                    ->cascadeOnDelete();

                $table->decimal('latitude', 10, 7);
                $table->decimal('longitude', 10, 7);
                $table->text('adresse')->nullable();
                $table->decimal('precision', 10, 2)->nullable();

                $table->timestamps();
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 7. TABLE TICKET ATTACHMENTS
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('ticket_attachments')) {
            Schema::create('ticket_attachments', function (Blueprint $table) {
                $table->id();

                $table->foreignId('ticket_id')
                    ->constrained('tickets')
                    ->cascadeOnDelete();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->string('type');
                $table->string('nom_fichier');
                $table->string('chemin_fichier');
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('taille')->nullable();

                $table->timestamps();
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 8. TABLE TICKET HISTORIES
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('ticket_histories')) {
            Schema::create('ticket_histories', function (Blueprint $table) {
                $table->id();

                $table->foreignId('ticket_id')
                    ->constrained('tickets')
                    ->cascadeOnDelete();

                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->string('action');
                $table->string('ancien_statut')->nullable();
                $table->string('nouveau_statut')->nullable();
                $table->text('commentaire')->nullable();
                $table->json('metadata')->nullable();

                $table->timestamps();
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 9. TABLE TICKET ASSIGNMENTS
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('ticket_assignments')) {
            Schema::create('ticket_assignments', function (Blueprint $table) {
                $table->id();

                $table->foreignId('ticket_id')
                    ->constrained('tickets')
                    ->cascadeOnDelete();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->timestamp('date_affectation')->useCurrent();
                $table->timestamp('date_fin')->nullable();
                $table->string('statut')->default('active');
                $table->text('commentaire')->nullable();

                $table->timestamps();
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 10. TABLE AI CONVERSATIONS
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('ai_conversations')) {
            Schema::create('ai_conversations', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->string('session_id')->index();

                $table->timestamps();
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 11. TABLE AI MESSAGES
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('ai_messages')) {
            Schema::create('ai_messages', function (Blueprint $table) {
                $table->id();

                $table->foreignId('conversation_id')
                    ->constrained('ai_conversations')
                    ->cascadeOnDelete();

                $table->string('role');
                $table->text('message');

                $table->timestamps();
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 12. INDEXES UTILES
        |--------------------------------------------------------------------------
        */

        // Les index supplémentaires pourront être ajoutés après vérification
        // des index déjà présents dans la base.
    }

    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Supprimer les tables créées par cette migration
        |--------------------------------------------------------------------------
        */

        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ticket_assignments');
        Schema::dropIfExists('ticket_histories');
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('ticket_locations');

        /*
        |--------------------------------------------------------------------------
        | Supprimer la colonne ajoutée à tickets
        |--------------------------------------------------------------------------
        */

        if (Schema::hasColumn('tickets', 'sous_entite_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropForeign(['sous_entite_id']);
                $table->dropColumn('sous_entite_id');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Supprimer la colonne ajoutée à elements
        |--------------------------------------------------------------------------
        */

        if (Schema::hasColumn('elements', 'description')) {
            Schema::table('elements', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Supprimer la colonne ajoutée à entites
        |--------------------------------------------------------------------------
        */

        if (Schema::hasColumn('entites', 'niveau')) {
            Schema::table('entites', function (Blueprint $table) {
                $table->dropColumn('niveau');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Supprimer les colonnes ajoutées à users
        |--------------------------------------------------------------------------
        */

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'photo')) {
                $table->dropColumn('photo');
            }

            if (Schema::hasColumn('users', 'adresse')) {
                $table->dropColumn('adresse');
            }
        });
    }
};
