<?php

namespace App\Http\Controllers;

use App\Models\Notifications;
use App\Models\Tickets;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class TicketController extends Controller
{

   public function ajouterTicket(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'type' => 'required|string',
        'categorie' => 'required|string',
        'urgence' => 'required|string',
        'source_demande' => 'required|string',
        'prenom' => 'required|string',
        'nom' => 'required|string',
        'email' => 'nullable|email',
        'telephone' => 'required|string|max:20',
        'adresse' => 'required|string',
        'detail' => 'required|string',
        'element_id' => 'nullable|exists:elements,id',
        'observateur_id' => 'nullable|exists:users,id',
        'dlgas_id' => 'nullable|string',
        'sla_ttr' => 'required|integer|min:3'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    try {

        $user = auth('api')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.'
            ], 401);
        }

        // 🔹 Génération numéro ticket
        $lastTicket = Tickets::latest('id')->first();
        $nextNumber = $lastTicket ? $lastTicket->id + 1 : 1;

        $numTicket = 'TCK-' . date('Y') . '-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

        $ticket = Tickets::create([
            'num_ticket' => $numTicket,

            'type' => $request->type,
            'categorie' => $request->categorie,
            'statut' => 'en_attente',
            'urgence' => $request->urgence,
            'date_ouverture' => now(),

            'sla_ttr' => $request->sla_ttr,
            'sla_started_at' => null,
            'sla_due_at' => null,

            'source_demande' => $request->source_demande,
            'prenom' => $request->prenom,
            'nom' => $request->nom,
            'email' => $request->email,
            'telephone' => $request->telephone,
            'adresse' => $request->adresse,
            'detail' => $request->detail,

            'user_id' => $user->id,
            'entite_id' => $user->entite_id,
            'element_id' => $request->element_id,
            'observateur_id' => $request->observateur_id,
            'dlgas_id' => null
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ticket créé avec succès.',
            'data' => $ticket
        ], 201);

    } catch (Throwable $e) {

        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la création du ticket.',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Lister les tickets avec pagination et filtres
     */
    public function listerTickets(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            // 🔎 Query avec relations
            $query = Tickets::with([
                'utilisateur:id,nom,prenom,username',
                'entite:id,nom',
                'element:id,nom'
            ]);

            //  Si ce n'est pas superadmin ou backoffice → il voit seulement ses tickets
            // Les profils autorisés à voir tous les tickets
            $profilsAutorises = ['superadmin', 'backoffice', 'selfservice'];

            if (!in_array($user->profil, $profilsAutorises)) {
                $query->where('user_id', $user->id);
            }

            // 📄 Pagination
            $perPage = $request->get('per_page', 5);
            $tickets = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Liste des tickets récupérée avec succès.',
                'data' => $tickets
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des tickets.',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }



    public function listerTicketsDlga(Request $request): JsonResponse
    {
        try {

            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            // Query avec relations
            $query = Tickets::with([
                'utilisateur:id,nom,prenom,username',
                'entite:id,nom',
                'element:id,nom',
                'dlgas:id,nom,prenom,username'
            ]);

            // 🔹 Si profil consultation → voir ses tickets assignés
            if ($user->profil === 'consultation') {

                $query->where('dlgas_id', $user->id);
            }
            // 🔹 Si superadmin → voir tous les tickets assignés aux dlgas
            elseif ($user->profil === 'superadmin') {

                $query->whereNotNull('dlgas_id');
            }
            // 🔹 Autres profils → voir leurs tickets créés
            else {

                $query->where('user_id', $user->id);
            }

            // pagination
            $perPage = $request->get('per_page', 5);

            $tickets = $query
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Liste des tickets récupérée avec succès.',
                'data' => $tickets
            ]);
        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des tickets.',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }

// public function listerTicketsDlga(Request $request): JsonResponse
// {
//     try {

//         // récupérer le profil et l'id depuis la requête pour le test
//         $profil = $request->get('profil');
//         $userId = $request->get('user_id');

//         // Query avec relations
//         $query = Tickets::with([
//             'utilisateur:id,nom,prenom,username',
//             'entite:id,nom',
//             'element:id,nom',
//             'dlgas:id,nom,prenom,username'
//         ]);

//         // Si profil = consultation (DLGAS)
//         if ($profil === 'consultation') {

//             $query->where('dlgas_id', $userId);

//         }
//         // Si superadmin → voir tous les tickets assignés
//         elseif ($profil === 'superadmin') {

//             $query->whereNotNull('dlgas_id');

//         }


//         // pagination
//         $perPage = $request->get('per_page', 5);

//         $tickets = $query
//             ->orderBy('created_at', 'desc')
//             ->paginate($perPage);

//         return response()->json([
//             'success' => true,
//             'message' => 'Liste des tickets récupérée avec succès.',
//             'data' => $tickets
//         ]);

//     } catch (\Throwable $e) {

//         return response()->json([
//             'success' => false,
//             'message' => 'Erreur lors de la récupération des tickets.',
//             'error' => $e->getMessage()
//         ], 500);
//     }
// }





    /**
     * Afficher un ticket spécifique
     */
    public function afficherTicket(int $id): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            // Récupérer le ticket avec ses relations
            $ticket = Tickets::with([
                'utilisateur:id,nom,prenom,username,email',
                'dlgas:id,nom,prenom,username,email',
                'entite:id,nom',
                'element:id,nom',
                'discussions' => function ($query) {
                    $query->orderBy('created_at', 'desc');
                },
                'discussions.utilisateur:id,nom,prenom'
            ])->find($id);

            if (!$ticket) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket non trouvé.'
                ], 404);
            }

            // Vérifier les droits d'accès
            $profilsAutorises = ['superadmin', 'backoffice', 'consultation'];
            if (!in_array($user->profil, $profilsAutorises) && $ticket->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous n\'avez pas accès à ce ticket.'
                ], 403);
            }

            // Formater les commentaires
            $commentaires = $ticket->discussions->map(function ($comment) {
                return [
                    'id' => $comment->id,
                    'texte' => $comment->contenu,
                    'user' => $comment->user ? $comment->user->prenom . ' ' . $comment->user->nom : 'Système',
                    'date' => $comment->created_at
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $ticket->id,
                    'type' => $ticket->type,
                    'categorie' => $ticket->categorie,
                    'statut' => $ticket->statut,
                    'urgence' => $ticket->urgence,
                    'source_demande' => $ticket->source_demande,
                    'prenom' => $ticket->prenom,
                    'nom' => $ticket->nom,
                    'email' => $ticket->email,
                    'telephone' => $ticket->telephone,
                    'adresse' => $ticket->adresse,
                    'detail' => $ticket->detail,
                    'user_id' => $ticket->user_id,
                    'utilisateur' => $ticket->utilisateur ? [
                        'id' => $ticket->utilisateur->id,
                        'nom' => $ticket->utilisateur->nom,
                        'prenom' => $ticket->utilisateur->prenom,
                        'email' => $ticket->utilisateur->email
                    ] : null,
                    'observateur_id' => $ticket->observateur_id,
                    'entite_id' => $ticket->entite_id,
                    // RELATION ENTITE
                    'entite' => $ticket->entite ? [
                        'id' => $ticket->entite->id,
                        'nom' => $ticket->entite->nom
                    ] : null,
                    'element_id' => $ticket->element_id,
                    // RELATION ELEMENT
                    'element' => $ticket->element ? [
                        'id' => $ticket->element->id,
                        'nom' => $ticket->element->nom
                    ] : null,
                    'dlgas_id' => $ticket->dlgas,
                    'dlgas' => $ticket->dlgas ? [
                        'id' => $ticket->dlgas->id,
                        'nom' => $ticket->dlgas->nom,
                        'prenom' => $ticket->dlgas->prenom,
                        'email' => $ticket->dlgas->email
                    ] : null,
                    'date_creation' => $ticket->created_at,
                    'commentaires' => $commentaires
                ]
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du ticket.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function modifierTicket(Request $request, int $id): JsonResponse
    {
        try {

            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            $ticket = Tickets::find($id);

            if (!$ticket) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket non trouvé.'
                ], 404);
            }

            $profilsAutorises = ['superadmin', 'backoffice'];

            if (
                !in_array($user->profil, $profilsAutorises)
                && $ticket->user_id !== $user->id
            ) {

                return response()->json([
                    'success' => false,
                    'message' => 'Vous n\'avez pas le droit de modifier ce ticket.'
                ], 403);
            }

            if ($ticket->statut === 'cloture') {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de modifier un ticket clôturé.'
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'type' => 'sometimes|string',
                'categorie' => 'sometimes|string',
                'statut' => 'sometimes|string',
                'urgence' => 'sometimes|string',
                'source_demande' => 'sometimes|string',
                'prenom' => 'sometimes|string',
                'nom' => 'sometimes|string',
                'email' => 'nullable|email',
                'telephone' => 'sometimes|string|max:20',
                'adresse' => 'sometimes|string',
                'detail' => 'sometimes|string',
                'element_id' => 'sometimes|exists:elements,id',
                'observateur_id' => 'nullable|exists:users,id',
                'dlgas_id' => 'nullable|exists:users,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();

            // 🎯 Si un DLGAS est assigné
            if (isset($data['dlgas_id'])) {

                $dlgas = User::find($data['dlgas_id']);

                if ($dlgas) {

                    // changer le statut automatiquement
                    $data['statut'] = 'en_cours';

                    if (!$ticket->sla_started_at) {

                        $slaStart = now();
                        $slaDue = now()->addHours($ticket->sla_ttr);

                        $data['sla_started_at'] = $slaStart;
                        $data['sla_due_at'] = $slaDue;
                    }

                    //  création notification avec nom et prénom
                    // Notifications::create([
                    //     'user_id' => $dlgas->id,
                    //     'ticket_id' => $ticket->id,
                    //     'type' => 'assignation_ticket',
                    //     'contenu' => "Bonjour {$dlgas->prenom} {$dlgas->nom}, un ticket (#{$ticket->id}) vous a été assigné.",
                    //     'lu' => false
                    // ]);
                }
            }

            $ticket->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Ticket modifié avec succès.',
                'data' => $ticket
            ]);
        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification du ticket.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function verifierSLA(int $ticketId): JsonResponse
    {
        try {

            $ticket = Tickets::find($ticketId);

            if (!$ticket) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket non trouvé.'
                ], 404);
            }

            if (!$ticket->sla_started_at || !$ticket->sla_due_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le SLA n\'a pas encore démarré.'
                ], 400);
            }

            $now = now();

            $start = Carbon::parse($ticket->sla_started_at);
            $due   = Carbon::parse($ticket->sla_due_at);

            $totalDuration = $start->diffInSeconds($due);
            $elapsedTime   = $start->diffInSeconds($now);

            $pourcentage = ($elapsedTime / $totalDuration) * 100;

            if ($pourcentage >= 100 && $ticket->type !== 'reclamation') {

                $ticket->update([
                    'type' => 'reclamation'
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'ticket_id' => $ticket->id,
                    'sla_pourcentage' => round($pourcentage, 2),
                    'type' => $ticket->type
                ]
            ]);
        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification du SLA',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function statistiques(): JsonResponse
    {
        try {

            // Total global
            $totalTickets = Tickets::count();

            if ($totalTickets == 0) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'total_tickets' => 0,
                        'resolus' => ['total' => 0, 'pourcentage' => 0],
                        'en_cours' => ['total' => 0, 'pourcentage' => 0],
                        'fermes' => ['total' => 0, 'pourcentage' => 0],
                        'haute_priorite' => ['total' => 0, 'pourcentage' => 0],
                    ]
                ]);
            }

            // Comptages optimisés
            $totalResolus = Tickets::where('statut', 'resolu')->count();
            $totalEnCours = Tickets::where('statut', 'en_cours')->count();
            $totalFermes  = Tickets::where('statut', 'ferme')->count();
            $totalHautePriorite = Tickets::where('urgence', 'haute')->orWhere('urgence', 'critique')->count();

            // Calcul pourcentages
            $pourcentage = fn($val) => round(($val / $totalTickets) * 100, 2);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_tickets' => $totalTickets,

                    'resolus' => [
                        'total' => $totalResolus,
                        'pourcentage' => $pourcentage($totalResolus)
                    ],

                    'en_cours' => [
                        'total' => $totalEnCours,
                        'pourcentage' => $pourcentage($totalEnCours)
                    ],

                    'fermes' => [
                        'total' => $totalFermes,
                        'pourcentage' => $pourcentage($totalFermes)
                    ],

                    'haute_priorite' => [
                        'total' => $totalHautePriorite,
                        'pourcentage' => $pourcentage($totalHautePriorite)
                    ],
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du calcul des statistiques',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function statistiquesGenerales(): JsonResponse
    {
        try {

            $totalUsers = User::count();
            $activeUsers = User::whereNotNull('derniere_connexion')->count();

            $totalTickets = Tickets::count();
            $resolvedTickets = Tickets::where('statut', 'resolu')->count();
            $openTickets = Tickets::where('statut', 'en_attente')->count();

            $avgResolutionTime = Tickets::whereNotNull('sla_started_at')
                ->whereNotNull('updated_at')
                ->selectRaw('AVG(EXTRACT(EPOCH FROM (updated_at - sla_started_at)) / 3600) as avg_time')
                ->value('avg_time');

            $percentage = fn($value, $total) => $total > 0 ? round(($value / $total) * 100, 2) : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'utilisateurs_actifs' => [
                        'total' => $activeUsers,
                        'pourcentage' => $percentage($activeUsers, $totalUsers)
                    ],
                    'tickets_crees' => [
                        'total' => $totalTickets,
                        'pourcentage' => 100
                    ],
                    'tickets_resolus' => [
                        'total' => $resolvedTickets,
                        'pourcentage' => $percentage($resolvedTickets, $totalTickets)
                    ],
                    'tickets_ouverts' => [
                        'total' => $openTickets,
                        'pourcentage' => $percentage($openTickets, $totalTickets)
                    ],
                    'temps_moyen_resolution' => [
                        'valeur' => round($avgResolutionTime, 2),
                        'unite' => 'heures'
                    ]
                ]
            ]);
        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur récupération statistiques',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function evolutionTickets(): JsonResponse
    {
        try {

            // Evolution par jour
            $parJour = Tickets::selectRaw("
                DATE(created_at) as date,
                COUNT(*) as tickets_crees,
                COUNT(*) FILTER (WHERE statut = 'resolu') as tickets_resolus
            ")
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            // Evolution par mois
            $parMois = Tickets::selectRaw("
                DATE_TRUNC('month', created_at) as mois,
                COUNT(*) as tickets_crees,
                COUNT(*) FILTER (WHERE statut = 'resolu') as tickets_resolus
            ")
                ->groupBy('mois')
                ->orderBy('mois')
                ->get();

            // Evolution par année
            $parAnnee = Tickets::selectRaw("
                DATE_TRUNC('year', created_at) as annee,
                COUNT(*) as tickets_crees,
                COUNT(*) FILTER (WHERE statut = 'resolu') as tickets_resolus
            ")
                ->groupBy('annee')
                ->orderBy('annee')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'par_jour' => $parJour,
                    'par_mois' => $parMois,
                    'par_annee' => $parAnnee
                ]
            ]);
        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur récupération évolution des tickets',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function repartitionStatuts(): JsonResponse
    {
        try {

            $totalTickets = Tickets::count();

            $enAttente = Tickets::where('statut', 'en_attente')->count();
            $enCours = Tickets::where('statut', 'en_cours')->count();
            $resolu = Tickets::where('statut', 'resolu')->count();

            $percentage = fn($value, $total) => $total > 0 ? round(($value / $total) * 100, 2) : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'en_attente' => [
                        'total' => $enAttente,
                        'pourcentage' => $percentage($enAttente, $totalTickets)
                    ],
                    'en_cours' => [
                        'total' => $enCours,
                        'pourcentage' => $percentage($enCours, $totalTickets)
                    ],
                    'resolu' => [
                        'total' => $resolu,
                        'pourcentage' => $percentage($resolu, $totalTickets)
                    ],
                    'total_tickets' => $totalTickets
                ]
            ]);
        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur récupération répartition des statuts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function supprimerTicket($id): JsonResponse
    {
        try {

            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            $ticket = Tickets::find($id);

            if (!$ticket) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket introuvable.'
                ], 404);
            }

            // profils autorisés
            $profilsAutorises = ['superadmin'];

            if (!in_array($user->profil, $profilsAutorises) && $ticket->user_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous n\'avez pas l\'autorisation de supprimer ce ticket.'
                ], 403);
            }

            $ticket->delete();

            return response()->json([
                'success' => true,
                'message' => 'Ticket supprimé avec succès.'
            ]);
        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du ticket.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
