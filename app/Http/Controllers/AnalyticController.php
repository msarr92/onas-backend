<?php

namespace App\Http\Controllers;

use App\Models\Entites;
use App\Models\Elements;
use App\Models\Tickets;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AnalyticController extends Controller
{
    /**
     * Vue d'ensemble de l'application ONAS.
     */
    public function vueEnsemble(): JsonResponse
    {
        try {
            $totalTickets = Tickets::count();

            $ticketsResolus = Tickets::where('statut', 'resolu')
                ->count();

            $ticketsEnCours = Tickets::whereIn('statut', [
                'en_cours',
                'en_attente',
            ])->count();

            $ticketsFermes = Tickets::where('statut', 'ferme')
                ->count();

            $ticketsOuverts = Tickets::whereIn('statut', [
                'ouvert',
                'nouveau',
            ])->count();

            $totalUtilisateurs = User::count();

            $totalEntites = Entites::count();

            $ticketsParStatut = Tickets::query()
                ->select('statut', DB::raw('COUNT(*) as total'))
                ->groupBy('statut')
                ->get();

            $ticketsParUrgence = Tickets::query()
                ->select('urgence', DB::raw('COUNT(*) as total'))
                ->groupBy('urgence')
                ->get();

            $ticketsRecents = Tickets::query()
                ->with(['utilisateur:id,nom,prenom'])
                ->latest()
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Vue d’ensemble récupérée avec succès.',
                'data' => [
                    'indicateurs' => [
                        'total_tickets' => $totalTickets,
                        'tickets_resolus' => $ticketsResolus,
                        'tickets_en_cours' => $ticketsEnCours,
                        'tickets_fermes' => $ticketsFermes,
                        'tickets_ouverts' => $ticketsOuverts,
                        'total_utilisateurs' => $totalUtilisateurs,
                        'total_entites' => $totalEntites,
                    ],
                    'tickets_par_statut' => $ticketsParStatut,
                    'tickets_par_urgence' => $ticketsParUrgence,
                    'tickets_recents' => $ticketsRecents,
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement de la vue d’ensemble.',
            ], 500);
        }
    }

    /**
     * ==========================================================
     * 2. TICKETS - ANALYSE COMPLÈTE (KPIs)
     * ==========================================================
     */
    public function ticketsKPIs(Request $request)
    {
        try {
            // 1. Validation des filtres
            $validated = $request->validate([
                'date_debut' => ['nullable', 'date'],
                'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
                'statut' => ['nullable', 'string', 'max:50'],
                'urgence' => ['nullable', 'string', 'max:50'],
                'type' => ['nullable', 'string', 'max:50'],
                'categorie' => ['nullable', 'string', 'max:100'],
                'entite_id' => ['nullable', 'integer', 'exists:entites,id'],
                'user_id' => ['nullable', 'integer', 'exists:users,id'],
            ]);

            // 2. Requête de base
            $query = Tickets::query();

            // 3. Filtre par période
            if (! empty($validated['date_debut'])) {
                $query->whereDate(
                    'date_ouverture',
                    '>=',
                    $validated['date_debut']
                );
            }

            if (! empty($validated['date_fin'])) {
                $query->whereDate(
                    'date_ouverture',
                    '<=',
                    $validated['date_fin']
                );
            }

            // 4. Filtres métier
            foreach (['statut', 'urgence', 'type', 'categorie'] as $field) {
                if (! empty($validated[$field])) {
                    $query->where($field, $validated[$field]);
                }
            }

            if (! empty($validated['entite_id'])) {
                $query->where('entite_id', $validated['entite_id']);
            }

            if (! empty($validated['user_id'])) {
                $query->where('user_id', $validated['user_id']);
            }

            // 5. Total des tickets correspondant aux filtres
            $totalTickets = (clone $query)->count();

            // 6. Répartition par statut
            $ticketsParStatut = (clone $query)
                ->select('statut', DB::raw('COUNT(*) as total'))
                ->groupBy('statut')
                ->get();

            // 7. Répartition par urgence
            $ticketsParUrgence = (clone $query)
                ->select('urgence', DB::raw('COUNT(*) as total'))
                ->groupBy('urgence')
                ->get();

            // 8. Indicateurs par statut
            $ticketsResolus = (clone $query)
                ->where('statut', 'resolu')
                ->count();

            $ticketsEnCours = (clone $query)
                ->whereIn('statut', ['en_cours', 'en_attente'])
                ->count();

            $ticketsOuverts = (clone $query)
                ->whereIn('statut', ['ouvert', 'nouveau'])
                ->count();

            $ticketsFermes = (clone $query)
                ->where('statut', 'ferme')
                ->count();

            // 9. Tickets prioritaires
            $ticketsUrgents = (clone $query)
                ->whereIn('urgence', ['haute', 'critique', 'urgente'])
                ->count();

            // 10. Calcul du délai moyen de résolution en heures
            $ticketsAvecDelai = (clone $query)
                ->where('statut', 'resolu')
                ->whereNotNull('date_ouverture')
                ->whereNotNull('updated_at')
                ->select('date_ouverture', 'updated_at')
                ->get();

            $delais = $ticketsAvecDelai->map(function ($ticket) {
                return Carbon::parse($ticket->date_ouverture)
                    ->diffInMinutes(
                        Carbon::parse($ticket->updated_at),
                        true
                    ) / 60;
            });

            $delaiMoyenResolution = $delais->isNotEmpty()
                ? round($delais->avg(), 2)
                : 0;

            // 11. Respect du SLA
            $ticketsAvecSla = (clone $query)
                ->whereNotNull('sla_due_at')
                ->whereNotNull('sla_started_at')
                ->get(['statut', 'sla_due_at', 'updated_at']);

            $ticketsSlaRespecte = $ticketsAvecSla
                ->filter(function ($ticket) {
                    if ($ticket->statut !== 'resolu') {
                        return false;
                    }

                    return Carbon::parse($ticket->updated_at)
                        ->lessThanOrEqualTo(
                            Carbon::parse($ticket->sla_due_at)
                        );
                })
                ->count();

            $ticketsSlaTermines = $ticketsAvecSla
                ->where('statut', 'resolu')
                ->count();

            $tauxRespectSla = $ticketsSlaTermines > 0
                ? round(
                    ($ticketsSlaRespecte / $ticketsSlaTermines) * 100,
                    2
                )
                : 0;

            // 12. Réponse JSON
            return response()->json([
                'success' => true,
                'message' => 'KPIs des tickets récupérés avec succès.',
                'data' => [
                    'total_tickets' => $totalTickets,
                    'tickets_resolus' => $ticketsResolus,
                    'tickets_en_cours' => $ticketsEnCours,
                    'tickets_ouverts' => $ticketsOuverts,
                    'tickets_fermes' => $ticketsFermes,
                    'tickets_urgents' => $ticketsUrgents,

                    'delai_moyen_resolution_heures' => $delaiMoyenResolution,

                    'sla' => [
                        'tickets_resolus_avec_sla' => $ticketsSlaTermines,
                        'tickets_sla_respectes' => $ticketsSlaRespecte,
                        'taux_respect_sla' => $tauxRespectSla,
                    ],

                    'repartition_statuts' => $ticketsParStatut,
                    'repartition_urgences' => $ticketsParUrgence,
                ],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’analyse des tickets.',
            ], 500);
        }
    }

    /**
     * ==========================================================
     * 3. TICKETS - ÉVOLUTION DANS LE TEMPS
     * ==========================================================
     */
    public function ticketsEvolution(Request $request)
    {
        $validated = $request->validate([
            'periode' => [
                'nullable',
                'string',
                Rule::in(['jour', 'semaine', 'mois', 'annee']),
            ],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => [
                'nullable',
                'date',
                'after_or_equal:date_debut',
            ],
            'statut' => ['nullable', 'string', 'max:50'],
            'urgence' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'string', 'max:50'],
            'categorie' => ['nullable', 'string', 'max:100'],
            'entite_id' => ['nullable', 'integer', 'exists:entites,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            $periode = $validated['periode'] ?? 'jour';

            /*
             * Déterminer le format de regroupement PostgreSQL.
             */
            $formatDate = match ($periode) {
                'jour' => 'YYYY-MM-DD',
                'semaine' => 'IYYY-"S"IW',
                'mois' => 'YYYY-MM',
                'annee' => 'YYYY',
            };

            /*
             * Requête principale.
             */
            $query = Tickets::query();

            /*
             * Filtrer sur la date d'ouverture.
             */
            if (! empty($validated['date_debut'])) {
                $query->whereDate(
                    'date_ouverture',
                    '>=',
                    $validated['date_debut']
                );
            }

            if (! empty($validated['date_fin'])) {
                $query->whereDate(
                    'date_ouverture',
                    '<=',
                    $validated['date_fin']
                );
            }

            /*
             * Filtres facultatifs.
             */
            foreach (['statut', 'urgence', 'type', 'categorie'] as $field) {
                if (! empty($validated[$field])) {
                    $query->where($field, $validated[$field]);
                }
            }

            if (! empty($validated['entite_id'])) {
                $query->where('entite_id', $validated['entite_id']);
            }

            if (! empty($validated['user_id'])) {
                $query->where('user_id', $validated['user_id']);
            }

            /*
             * Regroupement des tickets selon la période.
             * La valeur de formatDate est choisie parmi les valeurs
             * autorisées ci-dessus, elle ne provient pas directement
             * de la requête utilisateur.
             */
            $evolution = $query
                ->selectRaw(
                    "TO_CHAR(date_ouverture, '{$formatDate}') AS periode"
                )
                ->selectRaw('COUNT(*) AS total')
                ->groupBy('periode')
                ->orderBy('periode')
                ->get();

            /*
             * Retourner un tableau JSON compatible avec Observable<any[]>.
             */
            return response()->json(
                $evolution->map(function ($ligne) {
                    return [
                        'periode' => $ligne->periode,
                        'total' => (int) $ligne->total,
                    ];
                })->values()
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Erreur lors du calcul de l’évolution des tickets.',
            ], 500);
        }
    }

    /**
     * ==========================================================
     * 4. TICKETS - RÉPARTITION
     * ==========================================================
     */
    public function ticketsRepartition(Request $request)
    {
        try {
            // 1. Validation des filtres
            $validated = $request->validate([
                'date_debut' => ['nullable', 'date'],
                'date_fin' => [
                    'nullable',
                    'date',
                    'after_or_equal:date_debut',
                ],
                'statut' => ['nullable', 'string', 'max:50'],
                'urgence' => ['nullable', 'string', 'max:50'],
                'type' => ['nullable', 'string', 'max:50'],
                'categorie' => ['nullable', 'string', 'max:100'],
                'entite_id' => [
                    'nullable',
                    'integer',
                    'exists:entites,id',
                ],
                'user_id' => [
                    'nullable',
                    'integer',
                    'exists:users,id',
                ],
            ]);

            // 2. Requête de base
            $query = Tickets::query();

            // 3. Filtre sur la date d'ouverture
            if (! empty($validated['date_debut'])) {
                $query->whereDate(
                    'date_ouverture',
                    '>=',
                    $validated['date_debut']
                );
            }

            if (! empty($validated['date_fin'])) {
                $query->whereDate(
                    'date_ouverture',
                    '<=',
                    $validated['date_fin']
                );
            }

            // 4. Appliquer les filtres facultatifs
            foreach (['statut', 'urgence', 'type', 'categorie'] as $field) {
                if (! empty($validated[$field])) {
                    $query->where($field, $validated[$field]);
                }
            }

            if (! empty($validated['entite_id'])) {
                $query->where('entite_id', $validated['entite_id']);
            }

            if (! empty($validated['user_id'])) {
                $query->where('user_id', $validated['user_id']);
            }

            // 5. Répartition par statut
            $parStatut = (clone $query)
                ->select('statut as libelle')
                ->selectRaw('COUNT(*) AS total')
                ->groupBy('statut')
                ->orderByDesc('total')
                ->get();

            // 6. Répartition par niveau d'urgence
            $parUrgence = (clone $query)
                ->select('urgence as libelle')
                ->selectRaw('COUNT(*) AS total')
                ->groupBy('urgence')
                ->orderByDesc('total')
                ->get();

            // 7. Répartition par catégorie
            $parCategorie = (clone $query)
                ->select('categorie as libelle')
                ->selectRaw('COUNT(*) AS total')
                ->groupBy('categorie')
                ->orderByDesc('total')
                ->get();

            // 8. Répartition par type de ticket
            $parType = (clone $query)
                ->select('type as libelle')
                ->selectRaw('COUNT(*) AS total')
                ->groupBy('type')
                ->orderByDesc('total')
                ->get();

            // 9. Répartition par entité
            $parEntite = (clone $query)
                ->leftJoin(
                    'entites',
                    'tickets.entite_id',
                    '=',
                    'entites.id'
                )
                ->selectRaw(
                    "COALESCE(entites.nom, 'Sans entité') AS libelle"
                )
                ->selectRaw('COUNT(tickets.id) AS total')
                ->groupBy('entites.id', 'entites.nom')
                ->orderByDesc('total')
                ->get();

            // 10. Retourner les données
            return response()->json([
                'success' => true,
                'message' => 'Répartition des tickets récupérée avec succès.',
                'data' => [
                    'total_tickets' => (clone $query)->count(),

                    'par_statut' => $parStatut,
                    'par_urgence' => $parUrgence,
                    'par_categorie' => $parCategorie,
                    'par_type' => $parType,
                    'par_entite' => $parEntite,
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la répartition des tickets.',
            ], 500);
        }
    }

    /**
     * Appliquer les filtres communs aux analyses des tickets.
     */
    private function requeteTicketsFiltres(Request $request): Builder
    {
        $filters = $request->validate([
            'date_debut' => ['nullable', 'date'],
            'date_fin' => [
                'nullable',
                'date',
                'after_or_equal:date_debut',
            ],
            'statut' => ['nullable', 'string', 'max:50'],
            'urgence' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'string', 'max:50'],
            'categorie' => ['nullable', 'string', 'max:100'],
            'entite_id' => [
                'nullable',
                'integer',
                'exists:entites,id',
            ],
            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],
        ]);

        $query = Tickets::query();

        if (! empty($filters['date_debut'])) {
            $query->whereDate(
                'date_ouverture',
                '>=',
                $filters['date_debut']
            );
        }

        if (! empty($filters['date_fin'])) {
            $query->whereDate(
                'date_ouverture',
                '<=',
                $filters['date_fin']
            );
        }

        foreach (['statut', 'urgence', 'type', 'categorie'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['entite_id'])) {
            $query->where('entite_id', $filters['entite_id']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        return $query;
    }

    /**
     * ==========================================================
     * TICKETS PAR ENTITÉ
     * GET /api/analytic/tickets/par-entite
     * ==========================================================
     */
    public function ticketsParEntite(Request $request)
    {
        $query = $this->requeteTicketsFiltres($request);

        $resultats = $query
            ->leftJoin(
                'entites',
                'tickets.entite_id',
                '=',
                'entites.id'
            )
            ->selectRaw(
                "COALESCE(entites.nom, 'Sans entité') AS entite"
            )
            ->selectRaw('COUNT(tickets.id) AS total')
            ->groupBy('entites.id', 'entites.nom')
            ->orderByDesc('total')
            ->get();

        return response()->json(
            $resultats->map(function ($ligne) {
                return [
                    'entite' => $ligne->entite,
                    'total' => (int) $ligne->total,
                ];
            })->values()
        );
    }

    /**
     * ==========================================================
     * TICKETS PAR CATÉGORIE
     * GET /api/analytic/tickets/par-categorie
     * ==========================================================
     */
    public function ticketsParCategorie(Request $request)
    {
        $query = $this->requeteTicketsFiltres($request);

        $resultats = $query
            ->selectRaw(
                "COALESCE(NULLIF(categorie, ''), 'Sans catégorie') AS categorie"
            )
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('categorie')
            ->orderByDesc('total')
            ->get();

        return response()->json(
            $resultats->map(function ($ligne) {
                return [
                    'categorie' => $ligne->categorie,
                    'total' => (int) $ligne->total,
                ];
            })->values()
        );
    }

    /**
     * ==========================================================
     * TICKETS PAR PRIORITÉ
     * GET /api/analytic/tickets/par-priorite
     * ==========================================================
     */
    public function ticketsParPriorite(Request $request)
    {
        try {
            $query = $this->requeteTicketsFiltres($request);

            $resultats = $query
                ->selectRaw("
                COALESCE(
                    NULLIF(urgence, ''),
                    'non_definie'
                ) AS priorite
            ")
                ->selectRaw('COUNT(*) AS total')
                ->groupByRaw("
                COALESCE(
                    NULLIF(urgence, ''),
                    'non_definie'
                )
            ")
                ->orderByDesc('total')
                ->get();

            return response()->json(
                $resultats->map(function ($ligne) {
                    return [
                        'priorite' => $ligne->priorite,
                        'total' => (int) $ligne->total,
                    ];
                })->values()
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Erreur lors de la répartition des tickets par priorité.',
            ], 500);
        }
    }

    /**
     * ==========================================================
     * TICKETS PAR STATUT
     * GET /api/analytic/tickets/par-statut
     * ==========================================================
     */
    public function ticketsParStatut(Request $request)
    {
        try {
            $query = $this->requeteTicketsFiltres($request);

            $resultats = $query
                ->selectRaw("
                COALESCE(
                    NULLIF(statut, ''),
                    'non_defini'
                ) AS statut
            ")
                ->selectRaw('COUNT(*) AS total')
                ->groupByRaw("
                COALESCE(
                    NULLIF(statut, ''),
                    'non_defini'
                )
            ")
                ->orderByDesc('total')
                ->get();

            return response()->json(
                $resultats->map(function ($ligne) {
                    return [
                        'statut' => $ligne->statut,
                        'total' => (int) $ligne->total,
                    ];
                })->values()
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Erreur lors de la répartition des tickets par statut.',
            ], 500);
        }
    }

    /**
     * ==========================================================
     * TICKETS - ANALYSE DES SLA
     * GET /api/analytic/tickets/sla
     * ==========================================================
     */
    public function ticketsSLA(Request $request)
    {
        try {
            $query = $this->requeteTicketsFiltres($request);

            $tickets = $query
                ->whereNotNull('sla_due_at')
                ->get([
                    'id',
                    'statut',
                    'sla_started_at',
                    'sla_due_at',
                    'updated_at',
                ]);

            $maintenant = now();

            $totalAvecSla = $tickets->count();

            $ticketsResolus = $tickets->filter(
                fn ($ticket) => $ticket->statut === 'resolu'
            );

            $ticketsDansLesDelais = $tickets->filter(
                function ($ticket) use ($maintenant) {
                    $dateReference = $ticket->statut === 'resolu'
                        ? $ticket->updated_at
                        : $maintenant;

                    return $dateReference !== null
                        && Carbon::parse($dateReference)
                            ->lessThanOrEqualTo(
                                Carbon::parse($ticket->sla_due_at)
                            );
                }
            );

            $ticketsEnRetard = $tickets->filter(
                function ($ticket) use ($maintenant) {
                    if ($ticket->statut === 'resolu') {
                        return Carbon::parse($ticket->updated_at)
                            ->greaterThan(
                                Carbon::parse($ticket->sla_due_at)
                            );
                    }

                    return $maintenant->greaterThan(
                        Carbon::parse($ticket->sla_due_at)
                    );
                }
            );

            $ticketsEnCoursSla = $tickets->filter(
                fn ($ticket) => $ticket->statut !== 'resolu'
                    && $ticket->statut !== 'ferme'
                    && Carbon::parse($ticket->sla_due_at)
                        ->greaterThan($maintenant)
            );

            $tauxRespectSla = $totalAvecSla > 0
                ? round(
                    ($ticketsDansLesDelais->count() / $totalAvecSla) * 100,
                    2
                )
                : 0;

            return response()->json([
                'success' => true,
                'message' => 'Analyse SLA récupérée avec succès.',
                'data' => [
                    'total_tickets_avec_sla' => $totalAvecSla,
                    'tickets_resolus' => $ticketsResolus->count(),
                    'tickets_dans_les_delais' => $ticketsDansLesDelais->count(),
                    'tickets_en_retard' => $ticketsEnRetard->count(),
                    'tickets_en_cours_dans_les_delais' => $ticketsEnCoursSla->count(),
                    'taux_respect_sla' => $tauxRespectSla,
                ],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’analyse des SLA.',
            ], 500);
        }
    }

    /**
     * ==========================================================
     * TICKETS - ANALYSE DES DÉLAIS DE TRAITEMENT
     * GET /api/analytic/tickets/delais
     * ==========================================================
     */
    public function ticketsDelais(Request $request)
    {
        try {
            $query = $this->requeteTicketsFiltres($request);

            $tickets = $query->get([
                'id',
                'statut',
                'date_ouverture',
                'sla_started_at',
                'sla_due_at',
                'updated_at',
            ]);

            $maintenant = now();

            $ticketsResolus = $tickets->filter(
                fn ($ticket) => $ticket->statut === 'resolu'
            );

            // Durées de résolution en heures.
            $delaisResolution = $ticketsResolus
                ->filter(
                    fn ($ticket) => $ticket->date_ouverture !== null
                )
                ->map(function ($ticket) {
                    return Carbon::parse($ticket->date_ouverture)
                        ->diffInMinutes(
                            Carbon::parse($ticket->updated_at),
                            true
                        ) / 60;
                });

            $delaiMoyenResolution = $delaisResolution->isNotEmpty()
                ? round($delaisResolution->avg(), 2)
                : 0;

            $delaiMinimumResolution = $delaisResolution->isNotEmpty()
                ? round($delaisResolution->min(), 2)
                : 0;

            $delaiMaximumResolution = $delaisResolution->isNotEmpty()
                ? round($delaisResolution->max(), 2)
                : 0;

            // Durée écoulée pour les tickets non résolus.
            $ticketsNonResolus = $tickets->filter(
                fn ($ticket) => ! in_array(
                    $ticket->statut,
                    ['resolu', 'ferme'],
                    true
                )
            );

            $delaisEnCours = $ticketsNonResolus
                ->filter(
                    fn ($ticket) => $ticket->date_ouverture !== null
                )
                ->map(function ($ticket) use ($maintenant) {
                    return Carbon::parse($ticket->date_ouverture)
                        ->diffInMinutes($maintenant, true) / 60;
                });

            // Tickets dont le SLA est dépassé.
            $ticketsEnRetard = $ticketsNonResolus
                ->filter(function ($ticket) use ($maintenant) {
                    return $ticket->sla_due_at !== null
                        && $maintenant->greaterThan(
                            Carbon::parse($ticket->sla_due_at)
                        );
                });

            return response()->json([
                'success' => true,
                'message' => 'Analyse des délais récupérée avec succès.',
                'data' => [
                    'total_tickets' => $tickets->count(),
                    'total_tickets_resolus' => $ticketsResolus->count(),
                    'total_tickets_non_resolus' => $ticketsNonResolus->count(),

                    'delai_moyen_resolution_heures' => $delaiMoyenResolution,
                    'delai_minimum_resolution_heures' => $delaiMinimumResolution,
                    'delai_maximum_resolution_heures' => $delaiMaximumResolution,

                    'delai_moyen_tickets_en_cours_heures' => $delaisEnCours->isNotEmpty()
                            ? round($delaisEnCours->avg(), 2)
                            : 0,

                    'tickets_en_retard' => $ticketsEnRetard->count(),
                ],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’analyse des délais.',
            ], 500);
        }
    }

    /**
     * ==========================================================
     * TOP AGENTS - AGENTS LES PLUS ACTIFS
     * GET /api/analytic/tickets/top-agents
     * ==========================================================
     */
    public function topAgents(Request $request)
    {
        try {
            $validated = $request->validate([
                'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $limit = (int) ($validated['limit'] ?? 10);

            // Tickets respectant les filtres communs.
            $ticketsFiltres = $this->requeteTicketsFiltres($request)
                ->select('tickets.id');

            /*
             * Classement des agents à partir des affectations.
             * On compte chaque ticket une seule fois par agent.
             */
            $resultats = DB::table('ticket_assignments as ta')
                ->join('users as u', 'u.id', '=', 'ta.user_id')
                ->whereIn('ta.ticket_id', $ticketsFiltres)
                ->select(
                    'u.id as agent_id',
                    'u.nom',
                    'u.prenom'
                )
                ->selectRaw('COUNT(DISTINCT ta.ticket_id) AS total_tickets')
                ->selectRaw("
                COUNT(DISTINCT CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM tickets t
                        WHERE t.id = ta.ticket_id
                          AND t.statut = 'resolu'
                    )
                    THEN ta.ticket_id
                END) AS tickets_resolus
            ")
                ->groupBy('u.id', 'u.nom', 'u.prenom')
                ->orderByDesc('total_tickets')
                ->limit($limit)
                ->get();

            return response()->json(
                $resultats->map(function ($agent) {
                    return [
                        'agent_id' => $agent->agent_id,
                        'nom' => $agent->nom,
                        'prenom' => $agent->prenom,
                        'nom_complet' => trim(
                            ($agent->prenom ?? '').' '.($agent->nom ?? '')
                        ),
                        'total_tickets' => (int) $agent->total_tickets,
                        'tickets_resolus' => (int) $agent->tickets_resolus,
                    ];
                })->values()
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Erreur lors du classement des agents.',
            ], 500);
        }
    }

    /**
     * ==========================================================
     * TOP CRÉATEURS - UTILISATEURS AYANT CRÉÉ LE PLUS DE TICKETS
     * GET /api/analytic/tickets/top-createurs
     * ==========================================================
     */
    public function topCreateurs(Request $request)
    {
        try {
            $validated = $request->validate([
                'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $limit = (int) ($validated['limit'] ?? 10);

            // Tickets respectant les filtres communs.
            $ticketsFiltres = $this->requeteTicketsFiltres($request)
                ->select('tickets.id');

            $resultats = DB::table('tickets as t')
                ->join('users as u', 'u.id', '=', 't.user_id')
                ->whereIn('t.id', $ticketsFiltres)
                ->select(
                    'u.id as user_id',
                    'u.nom',
                    'u.prenom'
                )
                ->selectRaw('COUNT(t.id) AS total_tickets')
                ->groupBy('u.id', 'u.nom', 'u.prenom')
                ->orderByDesc('total_tickets')
                ->limit($limit)
                ->get();

            return response()->json(
                $resultats->map(function ($createur) {
                    return [
                        'user_id' => $createur->user_id,
                        'nom' => $createur->nom,
                        'prenom' => $createur->prenom,
                        'nom_complet' => trim(
                            ($createur->prenom ?? '').' '.($createur->nom ?? '')
                        ),
                        'total_tickets' => (int) $createur->total_tickets,
                    ];
                })->values()
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Erreur lors du classement des créateurs de tickets.',
            ], 500);
        }
    }

    public function utilisateursKPIs()
    {
        try {
            $dateLimite = now()->subDays(30)->startOfDay();

            $total = User::count();

            $actifs = User::whereNotNull('derniere_connexion')
                ->where('derniere_connexion', '>=', $dateLimite)
                ->count();

            $inscriptionsMois = User::whereBetween('created_at', [
                now()->startOfMonth(),
                now()->endOfMonth(),
            ])->count();

            $profils = User::query()
                ->whereNotNull('profil')
                ->distinct('profil')
                ->count('profil');

            return response()->json([
                'total_utilisateurs' => $total,
                'utilisateurs_actifs_30j' => $actifs,
                'utilisateurs_inactifs_30j' => $total - $actifs,
                'inscriptions_mois' => $inscriptionsMois,
                'nombre_profils' => $profils,
                'taux_activite_30j' => $total > 0
                    ? round(($actifs / $total) * 100, 2)
                    : 0,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Erreur lors du calcul des indicateurs utilisateurs.',
            ], 500);
        }
    }

    public function utilisateursParProfil()
    {
        try {
            $resultats = User::query()
                ->select('profil')
                ->selectRaw('COUNT(*) AS total')
                ->groupBy('profil')
                ->orderByDesc('total')
                ->get()
                ->map(function ($item) {
                    return [
                        'profil' => $item->profil ?? 'Non défini',
                        'total' => (int) $item->total,
                    ];
                });

            return response()->json($resultats);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Erreur lors de la répartition par profil.',
            ], 500);
        }
    }

    public function utilisateursParEntite()
    {
        try {
            $resultats = DB::table('users as u')
                ->leftJoin('entites as e', 'e.id', '=', 'u.entite_id')
                ->selectRaw("
                COALESCE(e.nom, 'Sans entité') AS entite
            ")
                ->selectRaw('COUNT(u.id) AS total')
                ->groupBy('e.id', 'e.nom')
                ->orderByDesc('total')
                ->get()
                ->map(function ($item) {
                    return [
                        'entite' => $item->entite,
                        'total' => (int) $item->total,
                    ];
                });

            return response()->json($resultats);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Erreur lors de la répartition par entité.',
            ], 500);
        }
    }

    public function utilisateursActifs(Request $request)
    {
        try {
            $validated = $request->validate([
                'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $limit = (int) ($validated['limit'] ?? 10);

            $resultats = User::query()
                ->whereNotNull('derniere_connexion')
                ->where(
                    'derniere_connexion',
                    '>=',
                    now()->subDays(30)->startOfDay()
                )
                ->orderByDesc('derniere_connexion')
                ->limit($limit)
                ->get([
                    'id',
                    'nom',
                    'prenom',
                    'username',
                    'email',
                    'profil',
                    'derniere_connexion',
                ])
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'nom' => $user->nom,
                        'prenom' => $user->prenom,
                        'nom_complet' => trim(
                            ($user->prenom ?? '').' '.($user->nom ?? '')
                        ),
                        'username' => $user->username,
                        'email' => $user->email,
                        'profil' => $user->profil,
                        'derniere_connexion' => $user->derniere_connexion,
                    ];
                });

            return response()->json($resultats);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Erreur lors de la récupération des utilisateurs actifs.',
            ], 500);
        }
    }

    public function utilisateursEvolution()
    {
        try {
            $dateDebut = now()
                ->subMonths(11)
                ->startOfMonth();

            $resultats = DB::table('users')
                ->where('created_at', '>=', $dateDebut)
                ->where('created_at', '<=', now())
                ->selectRaw("
                TO_CHAR(DATE_TRUNC('month', created_at), 'YYYY-MM') AS periode
            ")
                ->selectRaw('COUNT(*) AS total')
                ->groupByRaw("DATE_TRUNC('month', created_at)")
                ->orderByRaw("DATE_TRUNC('month', created_at) ASC")
                ->get()
                ->keyBy('periode');

            // Inclure les mois sans inscription, avec un total de zéro.
            $evolution = collect(range(0, 11))->map(function ($decalage) use ($resultats) {
                $mois = now()
                    ->startOfMonth()
                    ->subMonths(11 - $decalage);

                $periode = $mois->format('Y-m');

                return [
                    'periode' => $periode,
                    'mois' => $mois->translatedFormat('F Y'),
                    'total' => isset($resultats[$periode])
                        ? (int) $resultats[$periode]->total
                        : 0,
                ];
            });

            return response()->json($evolution);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Erreur lors du calcul de l’évolution des utilisateurs.',
            ], 500);
        }
    }

    public function entitesKPIs()
    {
        try {
            $total = DB::table('entites')->count();

            $entitesPrincipales = DB::table('entites')
                ->whereNull('entite_principale_id')
                ->count();

            $sousEntites = DB::table('entites')
                ->whereNotNull('entite_principale_id')
                ->count();

            $entitesAvecTickets = DB::table('tickets')
                ->whereNotNull('entite_id')
                ->distinct()
                ->count('entite_id');

            return response()->json([
                'total_entites' => $total,
                'entites_principales' => $entitesPrincipales,
                'sous_entites' => $sousEntites,
                'entites_avec_tickets' => $entitesAvecTickets,
                'entites_sans_tickets' => max(
                    0,
                    $total - $entitesAvecTickets
                ),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Erreur lors du calcul des indicateurs des entités.',
            ], 500);
        }
    }

    public function entitesPerformance()
    {
        try {
            $resultats = DB::table('entites as e')
                ->leftJoin('tickets as t', 't.entite_id', '=', 'e.id')
                ->select(
                    'e.id as entite_id',
                    'e.nom as entite'
                )
                ->selectRaw('COUNT(t.id) AS total_tickets')
                ->selectRaw("
                COUNT(t.id) FILTER (
                    WHERE t.statut = 'resolu'
                ) AS tickets_resolus
            ")
                ->selectRaw("
                COUNT(t.id) FILTER (
                    WHERE t.statut IN ('en_cours', 'en_attente')
                ) AS tickets_en_cours
            ")
                ->groupBy('e.id', 'e.nom')
                ->orderByDesc('total_tickets')
                ->get()
                ->map(function ($item) {
                    $total = (int) $item->total_tickets;
                    $resolus = (int) $item->tickets_resolus;

                    return [
                        'entite_id' => $item->entite_id,
                        'entite' => $item->entite,
                        'total_tickets' => $total,
                        'tickets_resolus' => $resolus,
                        'tickets_en_cours' => (int) $item->tickets_en_cours,
                        'taux_resolution' => $total > 0
                            ? round(($resolus / $total) * 100, 2)
                            : 0,
                    ];
                });

            return response()->json($resultats);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Erreur lors du calcul des performances des entités.',
            ], 500);
        }
    }

    public function entitesParType()
    {
        try {
            $resultats = DB::table('entites')
                ->selectRaw("
                CASE
                    WHEN entite_principale_id IS NULL
                        THEN 'Entité principale'
                    ELSE 'Sous-entité'
                END AS type
            ")
                ->selectRaw('COUNT(*) AS total')
                ->groupByRaw("
                CASE
                    WHEN entite_principale_id IS NULL
                        THEN 'Entité principale'
                    ELSE 'Sous-entité'
                END
            ")
                ->orderByDesc('total')
                ->get()
                ->map(function ($item) {
                    return [
                        'type' => $item->type,
                        'total' => (int) $item->total,
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'data' => $resultats,
            ], 200);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la répartition des entités par type.',
            ], 500);
        }
    }

    public function topEntites(Request $request)
    {
        try {
            $validated = $request->validate([
                'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $limit = (int) ($validated['limit'] ?? 10);

            $resultats = DB::table('entites as e')
                ->leftJoin('tickets as t', 't.entite_id', '=', 'e.id')
                ->select(
                    'e.id as entite_id',
                    'e.nom as entite'
                )
                ->selectRaw('COUNT(t.id) AS total_tickets')
                ->selectRaw("
                COUNT(t.id) FILTER (
                    WHERE t.statut = 'resolu'
                ) AS tickets_resolus
            ")
                ->groupBy('e.id', 'e.nom')
                ->orderByDesc('total_tickets')
                ->orderBy('e.nom')
                ->limit($limit)
                ->get()
                ->map(function ($item) {
                    return [
                        'entite_id' => $item->entite_id,
                        'entite' => $item->entite,
                        'total_tickets' => (int) $item->total_tickets,
                        'tickets_resolus' => (int) $item->tickets_resolus,
                    ];
                });

            return response()->json($resultats);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Erreur lors du classement des entités.',
            ], 500);
        }
    }

    public function elementsKPIs()
{
    try {
        $total = Elements::count();

        $elementsPrincipaux = Elements::whereNull(
            'element_principal_id'
        )->count();

        $sousElements = Elements::whereNotNull(
            'element_principal_id'
        )->count();

        return response()->json([
            'total_elements' => $total,
            'elements_principaux' => $elementsPrincipaux,
            'sous_elements' => $sousElements,
        ], 200);

    } catch (\Throwable $e) {
        report($e);

        return response()->json([
            'message' => 'Erreur lors du calcul des indicateurs des éléments.',
        ], 500);
    }
}

    public function elementsParType()
{
    try {
        $resultats = DB::table('elements')
            ->selectRaw("
                CASE
                    WHEN element_principal_id IS NULL
                        THEN 'Élément principal'
                    ELSE 'Sous-élément'
                END AS type
            ")
            ->selectRaw('COUNT(*) AS total')
            ->groupByRaw("
                CASE
                    WHEN element_principal_id IS NULL
                        THEN 'Élément principal'
                    ELSE 'Sous-élément'
                END
            ")
            ->orderByDesc('total')
            ->get()
            ->map(function ($item) {
                return [
                    'type' => $item->type,
                    'total' => (int) $item->total,
                ];
            })
            ->values();

        return response()->json($resultats, 200);

    } catch (\Throwable $e) {
        report($e);

        return response()->json([
            'message' => 'Erreur lors de la répartition des éléments par type.',
        ], 500);
    }
}

    public function elementsParEtat()
{
    try {
        $resultats = DB::table('elements')
            ->selectRaw("
                CASE
                    WHEN element_principal_id IS NULL
                        THEN 'Élément principal'
                    ELSE 'Sous-élément'
                END AS etat
            ")
            ->selectRaw('COUNT(*) AS total')
            ->groupByRaw("
                CASE
                    WHEN element_principal_id IS NULL
                        THEN 'Élément principal'
                    ELSE 'Sous-élément'
                END
            ")
            ->orderByDesc('total')
            ->get()
            ->map(function ($item) {
                return [
                    'etat' => $item->etat,
                    'total' => (int) $item->total,
                ];
            })
            ->values();

        return response()->json($resultats, 200);

    } catch (\Throwable $e) {
        report($e);

        return response()->json([
            'message' => 'Erreur lors de la répartition des éléments.',
        ], 500);
    }
}


    public function elementsParEntite()
{
    try {
        $resultats = DB::table('elements as el')
            ->leftJoin(
                'elements as parent',
                'parent.id',
                '=',
                'el.element_principal_id'
            )
            ->selectRaw("
                COALESCE(parent.nom, 'Élément principal') AS entite
            ")
            ->selectRaw('COUNT(el.id) AS total')
            ->groupBy('parent.id', 'parent.nom')
            ->orderByDesc('total')
            ->get()
            ->map(function ($item) {
                return [
                    'entite' => $item->entite,
                    'total' => (int) $item->total,
                ];
            })
            ->values();

        return response()->json($resultats, 200);

    } catch (\Throwable $e) {
        report($e);

        return response()->json([
            'message' => 'Erreur lors de la répartition des éléments.',
        ], 500);
    }
}


public function activiteKPIs()
{
    try {
        $aujourdHui = now()->toDateString();

        $debutMois = now()->startOfMonth();
        $finMois = now()->endOfMonth();

        $query = DB::table('tickets');

        $totalTickets = (clone $query)->count();

        $ticketsAujourdhui = (clone $query)
            ->whereDate('date_ouverture', $aujourdHui)
            ->count();

        $ticketsCeMois = (clone $query)
            ->whereBetween('date_ouverture', [
                $debutMois,
                $finMois,
            ])
            ->count();

        $ticketsResolus = (clone $query)
            ->where('statut', 'resolu')
            ->count();

        $ticketsEnAttente = (clone $query)
            ->where('statut', 'en_attente')
            ->count();

        $ticketsEnCours = (clone $query)
            ->where('statut', 'en_cours')
            ->count();

        return response()->json([
            'total_tickets' => $totalTickets,
            'tickets_aujourdhui' => $ticketsAujourdhui,
            'tickets_ce_mois' => $ticketsCeMois,
            'tickets_resolus' => $ticketsResolus,
            'tickets_en_attente' => $ticketsEnAttente,
            'tickets_en_cours' => $ticketsEnCours,
        ], 200);

    } catch (\Throwable $e) {
        report($e);

        return response()->json([
            'message' => "Erreur lors du calcul des indicateurs d'activité.",
        ], 500);
    }
}


public function activiteRecente(Request $request)
{
    try {
        $limit = max(1, min($request->integer('limit', 20), 100));

        $activites = Tickets::with('utilisateur')
            ->orderByDesc('date_ouverture')
            ->limit($limit)
            ->get()
            ->map(function ($ticket) {
                $utilisateur = $ticket->utilisateur;

                $nomComplet = $utilisateur
                    ? trim(
                        ($utilisateur->prenom ?? '') . ' ' .
                        ($utilisateur->nom ?? '')
                    )
                    : '';

                return [
                    'id' => $ticket->id,
                    'type' => 'ticket',
                    'description' => 'Ticket ' . ($ticket->num_ticket ?? $ticket->id),
                    'utilisateur' => $nomComplet !== ''
                        ? $nomComplet
                        : 'Utilisateur inconnu',
                    'statut' => $ticket->statut,
                    'urgence' => $ticket->urgence,
                    'date' => $ticket->date_ouverture,
                ];
            });

        return response()->json($activites);

    } catch (\Throwable $e) {
        report($e);

        return response()->json([
            'message' => 'Erreur lors de la récupération des activités récentes.'
        ], 500);
    }
}



public function activiteParAction(Request $request)
{
    try {
        $activites = DB::table('tickets')
            ->select(
                'type as action',
                DB::raw('COUNT(*) as total')
            )
            ->whereNotNull('type')
            ->groupBy('type')
            ->orderByDesc('total')
            ->get()
            ->map(function ($item) {
                return [
                    'action' => $item->action,
                    'total' => (int) $item->total,
                ];
            });

        return response()->json($activites);

    } catch (\Throwable $e) {
        report($e);

        return response()->json([
            'message' => 'Erreur lors du calcul des activités par action.'
        ], 500);
    }
}

public function activiteParHeure()
{
    try {
        $activites = DB::table('tickets')
            ->selectRaw('EXTRACT(HOUR FROM date_ouverture)::integer AS heure')
            ->selectRaw('COUNT(*) AS total')
            ->whereNotNull('date_ouverture')
            ->groupByRaw('EXTRACT(HOUR FROM date_ouverture)')
            ->orderBy('heure')
            ->get()
            ->map(function ($item) {
                return [
                    'heure' => sprintf('%02d:00', $item->heure),
                    'total' => (int) $item->total,
                ];
            });

        return response()->json($activites);

    } catch (\Throwable $e) {
        report($e);

        return response()->json([
            'message' => 'Erreur lors du calcul des activités par heure.'
        ], 500);
    }
}


public function categories()
{
    try {
        $categories = DB::table('tickets')
            ->whereNotNull('categorie')
            ->where('categorie', '<>', '')
            ->select('categorie')
            ->distinct()
            ->orderBy('categorie')
            ->pluck('categorie')
            ->map(function ($categorie) {
                return [
                    'nom' => $categorie,
                    'total' => DB::table('tickets')
                        ->where('categorie', $categorie)
                        ->count(),
                ];
            })
            ->values();

        return response()->json($categories);

    } catch (\Throwable $e) {
        report($e);

        return response()->json([
            'message' => 'Erreur lors de la récupération des catégories.'
        ], 500);
    }
}


public function sourcesDemande()
{
    try {
        $sources = DB::table('tickets')
            ->whereNotNull('source_demande')
            ->where('source_demande', '<>', '')
            ->select('source_demande')
            ->distinct()
            ->orderBy('source_demande')
            ->pluck('source_demande')
            ->map(function ($source) {
                return [
                    'nom' => $source,
                    'total' => DB::table('tickets')
                        ->where('source_demande', $source)
                        ->count(),
                ];
            })
            ->values();

        return response()->json($sources);

    } catch (\Throwable $e) {
        report($e);

        return response()->json([
            'message' => 'Erreur lors de la récupération des sources de demande.'
        ], 500);
    }
}



public function performanceGlobale()
{
    try {
        $total = DB::table('tickets')->count();

        $resolus = DB::table('tickets')
            ->where('statut', 'resolu')
            ->count();

        $enAttente = DB::table('tickets')
            ->where('statut', 'en_attente')
            ->count();

        $enCours = DB::table('tickets')
            ->where('statut', 'en_cours')
            ->count();

        $hautePriorite = DB::table('tickets')
            ->whereIn('urgence', ['haute', 'critique'])
            ->count();

        $tauxResolution = $total > 0
            ? round(($resolus / $total) * 100, 2)
            : 0;

        $slaExpires = DB::table('tickets')
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', now())
            ->whereNotIn('statut', ['resolu', 'ferme', 'fermé'])
            ->count();

        return response()->json([
            'total_tickets' => $total,
            'tickets_resolus' => $resolus,
            'tickets_en_attente' => $enAttente,
            'tickets_en_cours' => $enCours,
            'tickets_haute_priorite' => $hautePriorite,
            'taux_resolution' => $tauxResolution,
            'tickets_sla_depasse' => $slaExpires,
        ]);

    } catch (\Throwable $e) {
        report($e);

        return response()->json([
            'message' => 'Erreur lors du calcul de la performance globale.'
        ], 500);
    }
}



public function alertes()
{
    try {
        $tickets = DB::table('tickets')
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', now())
            ->whereNotIn('statut', ['resolu', 'ferme', 'fermé'])
            ->orderBy('sla_due_at')
            ->get([
                'id',
                'num_ticket',
                'categorie',
                'statut',
                'urgence',
                'sla_due_at',
                'date_ouverture',
            ]);

        $alertes = $tickets->map(function ($ticket) {
            return [
                'id' => $ticket->id,
                'ticket' => $ticket->num_ticket ?? $ticket->id,
                'categorie' => $ticket->categorie,
                'statut' => $ticket->statut,
                'urgence' => $ticket->urgence,
                'type' => 'sla_depasse',
                'message' => 'Le délai SLA de ce ticket est dépassé.',
                'date_echeance' => $ticket->sla_due_at,
            ];
        });

        return response()->json($alertes);

    } catch (\Throwable $e) {
        report($e);

        return response()->json([
            'message' => 'Erreur lors de la récupération des alertes.'
        ], 500);
    }
}



}
