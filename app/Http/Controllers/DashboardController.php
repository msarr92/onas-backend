<?php

namespace App\Http\Controllers;

use App\Models\Tickets;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function activiteRecente(Request $request)
    {
        try {

            $limit = min((int) $request->get('limit', 10), 50);

            $activites = collect();

            /*
            |--------------------------------------------------------------------------
            | 1. Tickets récemment créés
            |--------------------------------------------------------------------------
            */

            $tickets = Tickets::with([
                'utilisateur:id,nom,prenom,username,email',
            ])
                ->latest('created_at')
                ->limit($limit)
                ->get();

            foreach ($tickets as $ticket) {

                $user = $ticket->utilisateur;

                $activites->push([
                    'id' => 'ticket_'.$ticket->id,

                    'type' => 'ticket',

                    'action' => 'creation',

                    'titre' => 'Nouveau ticket créé',

                    'description' => 'Le ticket #'.
                        ($ticket->num_ticket ?? $ticket->id).
                        ' a été créé',

                    'ticket_id' => $ticket->id,

                    'ticket_numero' => $ticket->num_ticket ?? null,

                    'utilisateur' => $user ? [
                        'id' => $user->id,

                        'nom' => trim(
                            ($user->prenom ?? '').
                            ' '.
                            ($user->nom ?? '')
                        ),

                        'email' => $user->email,
                    ] : null,

                    'date' => $ticket->created_at,

                    'date_formattee' => $ticket->created_at?->diffForHumans(),
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | 2. Utilisateurs récemment créés
            |--------------------------------------------------------------------------
            */

            $users = User::latest('created_at')
                ->limit($limit)
                ->get();

            foreach ($users as $user) {

                $activites->push([
                    'id' => 'user_'.$user->id,

                    'type' => 'utilisateur',

                    'action' => 'creation',

                    'titre' => 'Nouvel utilisateur',

                    'description' => 'L’utilisateur '.
                        trim(
                            ($user->prenom ?? '').
                            ' '.
                            ($user->nom ?? '')
                        ).
                        ' a été créé',

                    'utilisateur' => [
                        'id' => $user->id,

                        'nom' => trim(
                            ($user->prenom ?? '').
                            ' '.
                            ($user->nom ?? '')
                        ),

                        'email' => $user->email,
                    ],

                    'date' => $user->created_at,

                    'date_formattee' => $user->created_at?->diffForHumans(),
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | 3. Trier toutes les activités par date
            |--------------------------------------------------------------------------
            */

            $activites = $activites
                ->sortByDesc('date')
                ->take($limit)
                ->values();

            return response()->json([
                'success' => true,

                'message' => 'Activités récentes récupérées avec succès',

                'data' => $activites,

                'total' => $activites->count(),
            ], 200);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,

                'message' => 'Erreur lors de la récupération des activités récentes',

                'error' => $e->getMessage(),

                'file' => $e->getFile(),

                'line' => $e->getLine(),
            ], 500);
        }
    }

    public function activiteUtilisateurs(Request $request)
    {
        try {

            $limit = min((int) $request->get('limit', 10), 100);

            /*
            |--------------------------------------------------------------------------
            | 1. Récupérer les utilisateurs
            |--------------------------------------------------------------------------
            */

            $users = User::query()
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get([
                    'id',
                    'nom',
                    'prenom',
                    'username',
                    'email',
                    'derniere_connexion',
                    'created_at',
                ]);

            /*
            |--------------------------------------------------------------------------
            | 2. IDs des utilisateurs
            |--------------------------------------------------------------------------
            */

            $userIds = $users
                ->pluck('id')
                ->values();

            /*
            |--------------------------------------------------------------------------
            | 3. Nombre de tickets créés par utilisateur
            |--------------------------------------------------------------------------
            */

            $ticketsCrees = Tickets::whereIn('user_id', $userIds)
                ->selectRaw('user_id, COUNT(*) as total')
                ->groupBy('user_id')
                ->pluck('total', 'user_id');

            /*
            |--------------------------------------------------------------------------
            | 4. Nombre de tickets actuellement affectés à chaque DLGAS
            |--------------------------------------------------------------------------
            */

            $ticketsAssignes = Tickets::whereIn('dlgas_id', $userIds)
                ->selectRaw('dlgas_id, COUNT(*) as total')
                ->groupBy('dlgas_id')
                ->pluck('total', 'dlgas_id');

            /*
            |--------------------------------------------------------------------------
            | 5. Dernier ticket créé par chaque utilisateur
            |--------------------------------------------------------------------------
            */

            $derniersTickets = Tickets::whereIn('user_id', $userIds)
                ->orderByDesc('created_at')
                ->get([
                    'id',
                    'user_id',
                    'num_ticket',
                    'created_at',
                ]);

            /*
            |--------------------------------------------------------------------------
            | Garder uniquement le dernier ticket de chaque utilisateur
            |--------------------------------------------------------------------------
            */

            $derniersTickets = $derniersTickets
                ->groupBy('user_id')
                ->map(function ($tickets) {
                    return $tickets->first();
                });

            /*
            |--------------------------------------------------------------------------
            | 6. Construire la réponse
            |--------------------------------------------------------------------------
            */

            $data = $users->map(function ($user) use (
                $ticketsCrees,
                $ticketsAssignes,
                $derniersTickets
            ) {

                $dernierTicket = $derniersTickets->get($user->id);

                $nombreTicketsCrees =
                    (int) ($ticketsCrees->get($user->id) ?? 0);

                $nombreTicketsAssignes =
                    (int) ($ticketsAssignes->get($user->id) ?? 0);

                /*
                |--------------------------------------------------------------------------
                | Déterminer la dernière activité
                |--------------------------------------------------------------------------
                |
                | Priorité :
                | 1. Dernier ticket créé
                | 2. Dernière connexion
                | 3. Date de création du compte
                |
                */

                $dateDerniereActivite = null;

                if ($dernierTicket && ! empty($dernierTicket->created_at)) {

                    $dateDerniereActivite = Carbon::parse(
                        $dernierTicket->created_at
                    );

                } elseif (! empty($user->derniere_connexion)) {

                    $dateDerniereActivite = Carbon::parse(
                        $user->derniere_connexion
                    );

                } elseif (! empty($user->created_at)) {

                    $dateDerniereActivite = Carbon::parse(
                        $user->created_at
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Construire l'utilisateur
                |--------------------------------------------------------------------------
                */

                return [

                    /*
                    |--------------------------------------------------------------------------
                    | Informations utilisateur
                    |--------------------------------------------------------------------------
                    */

                    'id' => $user->id,

                    'nom' => trim(
                        ($user->prenom ?? '').
                        ' '.
                        ($user->nom ?? '')
                    ),

                    'username' => $user->username,

                    'email' => $user->email,

                    /*
                    |--------------------------------------------------------------------------
                    | Activité ticket
                    |--------------------------------------------------------------------------
                    */

                    'tickets_crees' => $nombreTicketsCrees,

                    'tickets_assignes' => $nombreTicketsAssignes,

                    /*
                    |--------------------------------------------------------------------------
                    | Dernier ticket
                    |--------------------------------------------------------------------------
                    */

                    'dernier_ticket' => $dernierTicket ? [

                        'id' => $dernierTicket->id,

                        'num_ticket' => $dernierTicket->num_ticket,

                    ] : null,

                    /*
                    |--------------------------------------------------------------------------
                    | Dernière activité
                    |--------------------------------------------------------------------------
                    */

                    'derniere_activite' => $dateDerniereActivite
                        ? $dateDerniereActivite->toDateTimeString()
                        : null,

                    'derniere_activite_formattee' => $dateDerniereActivite
                        ? $dateDerniereActivite->diffForHumans()
                        : null,

                    /*
                    |--------------------------------------------------------------------------
                    | Statut activité
                    |--------------------------------------------------------------------------
                    */

                    'actif' => $dernierTicket !== null
                        || ! empty($user->derniere_connexion),
                ];
            });

            /*
            |--------------------------------------------------------------------------
            | Trier les utilisateurs par dernière activité
            |--------------------------------------------------------------------------
            */

            $data = $data
                ->sortByDesc(function ($user) {
                    return $user['derniere_activite'] ?? '';
                })
                ->values();

            /*
            |--------------------------------------------------------------------------
            | Réponse JSON
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,
                'message' => 'Activité des utilisateurs récupérée avec succès',
                'data' => $data,
                'total' => $data->count(),
            ], 200);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l’activité des utilisateurs',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    public function ticketsRecents(Request $request)
    {
        try {

            $limit = min((int) $request->get('limit', 10), 50);

            /*
            |--------------------------------------------------------------------------
            | Récupération des tickets récents
            |--------------------------------------------------------------------------
            */

            $tickets = Tickets::with([
                'utilisateur:id,nom,prenom,username,email',
            ])
                ->latest('created_at')
                ->limit($limit)
                ->get();

            /*
            |--------------------------------------------------------------------------
            | Récupération des DLGAS responsables
            |--------------------------------------------------------------------------
            */

            $dlgasIds = $tickets
                ->pluck('dlgas_id')
                ->filter()
                ->unique()
                ->values();

            $dlgas = User::whereIn('id', $dlgasIds)
                ->get([
                    'id',
                    'nom',
                    'prenom',
                    'username',
                    'email',
                ])
                ->keyBy('id');

            /*
            |--------------------------------------------------------------------------
            | Transformation des données
            |--------------------------------------------------------------------------
            */

            $data = $tickets->map(function ($ticket) use ($dlgas) {

                $demandeur = $ticket->utilisateur;

                $responsable = $ticket->dlgas_id
                    ? $dlgas->get($ticket->dlgas_id)
                    : null;

                /*
                |--------------------------------------------------------------------------
                | Conversion sécurisée des dates
                |--------------------------------------------------------------------------
                */

                $dateOuverture = ! empty($ticket->date_ouverture)
                    ? Carbon::parse($ticket->date_ouverture)
                    : null;

                $dateCreation = ! empty($ticket->created_at)
                    ? Carbon::parse($ticket->created_at)
                    : null;

                $dateModification = ! empty($ticket->updated_at)
                    ? Carbon::parse($ticket->updated_at)
                    : null;

                return [

                    /*
                    |--------------------------------------------------------------------------
                    | Informations ticket
                    |--------------------------------------------------------------------------
                    */

                    'id' => $ticket->id,

                    'num_ticket' => $ticket->num_ticket
                        ?? ('TCK-'.$ticket->id),

                    'type' => $ticket->type,

                    'categorie' => $ticket->categorie,

                    'statut' => $ticket->statut,

                    'urgence' => $ticket->urgence,

                    'source_demande' => $ticket->source_demande,

                    'detail' => $ticket->detail,

                    /*
                    |--------------------------------------------------------------------------
                    | SLA
                    |--------------------------------------------------------------------------
                    */

                    'sla_ttr' => $ticket->sla_ttr,

                    'sla_started_at' => $ticket->sla_started_at,

                    'sla_due_at' => $ticket->sla_due_at,

                    /*
                    |--------------------------------------------------------------------------
                    | Demandeur
                    |--------------------------------------------------------------------------
                    */

                    'demandeur' => $demandeur ? [

                        'id' => $demandeur->id,

                        'nom' => trim(
                            ($demandeur->prenom ?? '').
                            ' '.
                            ($demandeur->nom ?? '')
                        ),

                        'username' => $demandeur->username,

                        'email' => $demandeur->email,

                    ] : null,

                    /*
                |--------------------------------------------------------------------------
                | Responsable DLGAS
                |--------------------------------------------------------------------------
                */

                    'dlgas' => $responsable ? [

                        'id' => $responsable->id,

                        'nom' => trim(
                            ($responsable->prenom ?? '').
                            ' '.
                            ($responsable->nom ?? '')
                        ),

                        'username' => $responsable->username,

                        'email' => $responsable->email,

                    ] : null,

                    /*
                |--------------------------------------------------------------------------
                | Dates
                |--------------------------------------------------------------------------
                */

                    'date_ouverture' => $ticket->date_ouverture,

                    'date_ouverture_formattee' => $dateOuverture
                        ? $dateOuverture->diffForHumans()
                        : null,

                    'date_creation' => $ticket->created_at,

                    'date_creation_formattee' => $dateCreation
                        ? $dateCreation->diffForHumans()
                        : null,

                    'date_modification' => $ticket->updated_at,
                ];
            });

            /*
            |--------------------------------------------------------------------------
            | Réponse
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,
                'message' => 'Tickets récents récupérés avec succès',
                'data' => $data,
                'total' => $data->count(),
            ], 200);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des tickets récents',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    public function statistiques(): JsonResponse
    {
        try {

            // ============================================================
            // UTILISATEURS
            // ============================================================

            $totalUsers = User::count();

            // Utilisateurs ayant déjà effectué au moins une connexion
            $activeUsers = User::whereNotNull('derniere_connexion')
                ->count();

            // ============================================================
            // TICKETS
            // ============================================================

            $totalTickets = Tickets::count();

            $resolvedTickets = Tickets::where(
                'statut',
                'resolu'
            )->count();

            $openTickets = Tickets::whereIn(
                'statut',
                [
                    'nouveau',
                    'en_attente',
                    'en_cours',
                ]
            )->count();

            // ============================================================
            // POURCENTAGE
            // ============================================================

            $percentage = function ($value, $total) {

                if ($total <= 0) {
                    return 0;
                }

                return round(
                    ($value / $total) * 100,
                    2
                );
            };

            // ============================================================
            // TEMPS MOYEN DE RESOLUTION
            // ============================================================
            //
            // On utilise ticket_histories pour rechercher le moment
            // où chaque ticket est passé à "resolu".
            //
            // Cela est plus fiable que updated_at.
            // ============================================================

            $resolutionTimes = DB::table('tickets')
                ->join(
                    'ticket_histories',
                    'ticket_histories.ticket_id',
                    '=',
                    'tickets.id'
                )
                ->where(
                    'ticket_histories.nouveau_statut',
                    'resolu'
                )
                ->selectRaw('
                EXTRACT(
                    EPOCH FROM (
                        ticket_histories.created_at
                        - tickets.date_ouverture
                    )
                ) / 3600 AS temps_resolution
            ')
                ->get();

            $avgResolutionTime = $resolutionTimes->avg(
                'temps_resolution'
            );

            // ============================================================
            // REPONSE
            // ============================================================

            return response()->json([

                'success' => true,

                'message' => 'Statistiques récupérées avec succès.',

                'data' => [

                    'utilisateurs_actifs' => [

                        'total' => $activeUsers,

                        'pourcentage' => $percentage(
                            $activeUsers,
                            $totalUsers
                        ),
                    ],

                    'tickets_crees' => [

                        'total' => $totalTickets,

                        'pourcentage' => 100,
                    ],

                    'tickets_resolus' => [

                        'total' => $resolvedTickets,

                        'pourcentage' => $percentage(
                            $resolvedTickets,
                            $totalTickets
                        ),
                    ],

                    'tickets_ouverts' => [

                        'total' => $openTickets,

                        'pourcentage' => $percentage(
                            $openTickets,
                            $totalTickets
                        ),
                    ],

                    'temps_moyen_resolution' => [

                        'valeur' => round(
                            $avgResolutionTime ?? 0,
                            2
                        ),

                        'unite' => 'heures',
                    ],
                ],

            ], 200);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors de la récupération des statistiques.',

                'error' => $e->getMessage(),

            ], 500);
        }
    }

    public function repartitionStatuts(): JsonResponse
    {
        try {

            // ============================================================
            // TOTAL
            // ============================================================

            $totalTickets = Tickets::count();

            // ============================================================
            // NOMBRE DE TICKETS PAR STATUT
            // ============================================================

            $statuts = Tickets::select(
                'statut',
                DB::raw('COUNT(*) as total')
            )
                ->groupBy('statut')
                ->get();

            // ============================================================
            // POURCENTAGE
            // ============================================================

            $percentage = function ($value, $total) {

                if ($total <= 0) {
                    return 0;
                }

                return round(
                    ($value / $total) * 100,
                    2
                );
            };

            // ============================================================
            // Construire une réponse standardisée
            // ============================================================

            $resultat = [];

            foreach ($statuts as $statut) {

                $resultat[$statut->statut] = [

                    'total' => (int) $statut->total,

                    'pourcentage' => $percentage(
                        $statut->total,
                        $totalTickets
                    ),
                ];
            }

            // ============================================================
            // Ajouter les statuts absents avec 0
            // ============================================================

            $statutsPossibles = [
                'nouveau',
                'en_attente',
                'en_cours',
                'resolu',
            ];

            foreach ($statutsPossibles as $statut) {

                if (! isset($resultat[$statut])) {

                    $resultat[$statut] = [

                        'total' => 0,

                        'pourcentage' => 0,
                    ];
                }
            }

            // ============================================================
            // TOTAL
            // ============================================================

            $resultat['total_tickets'] = $totalTickets;

            // ============================================================
            // REPONSE
            // ============================================================

            return response()->json([

                'success' => true,

                'message' => 'Répartition des tickets récupérée avec succès.',

                'data' => $resultat,

            ], 200);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors de la récupération de la répartition des tickets.',

                'error' => $e->getMessage(),

            ], 500);
        }
    }

    public function evolutionTickets(): JsonResponse
    {
        try {

            // ============================================================
            // EVOLUTION PAR JOUR
            // 30 derniers jours
            // ============================================================

            $parJour = Tickets::selectRaw("
                DATE(created_at) AS date,

                COUNT(*) AS tickets_crees,

                COUNT(*) FILTER (
                    WHERE statut = 'resolu'
                ) AS tickets_resolus
            ")
                ->where(
                    'created_at',
                    '>=',
                    now()->subDays(30)->startOfDay()
                )
                ->groupByRaw(
                    'DATE(created_at)'
                )
                ->orderByRaw(
                    'DATE(created_at)'
                )
                ->get();

            // ============================================================
            // EVOLUTION PAR MOIS
            // 12 derniers mois
            // ============================================================

            $parMois = Tickets::selectRaw("
                DATE_TRUNC(
                    'month',
                    created_at
                ) AS mois,

                COUNT(*) AS tickets_crees,

                COUNT(*) FILTER (
                    WHERE statut = 'resolu'
                ) AS tickets_resolus
            ")
                ->where(
                    'created_at',
                    '>=',
                    now()->subMonths(12)->startOfMonth()
                )
                ->groupByRaw("
                DATE_TRUNC(
                    'month',
                    created_at
                )
            ")
                ->orderByRaw("
                DATE_TRUNC(
                    'month',
                    created_at
                )
            ")
                ->get();

            // ============================================================
            // EVOLUTION PAR ANNEE
            // ============================================================

            $parAnnee = Tickets::selectRaw("
                DATE_TRUNC(
                    'year',
                    created_at
                ) AS annee,

                COUNT(*) AS tickets_crees,

                COUNT(*) FILTER (
                    WHERE statut = 'resolu'
                ) AS tickets_resolus
            ")
                ->groupByRaw("
                DATE_TRUNC(
                    'year',
                    created_at
                )
            ")
                ->orderByRaw("
                DATE_TRUNC(
                    'year',
                    created_at
                )
            ")
                ->get();

            // ============================================================
            // REPONSE
            // ============================================================

            return response()->json([

                'success' => true,

                'message' => 'Évolution des tickets récupérée avec succès.',

                'data' => [

                    'par_jour' => $parJour,

                    'par_mois' => $parMois,

                    'par_annee' => $parAnnee,

                ],

            ], 200);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors de la récupération de l’évolution des tickets.',

                'error' => $e->getMessage(),

            ], 500);
        }
    }
}
