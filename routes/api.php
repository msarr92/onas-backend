<?php

namespace Route\Api;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AnalyticController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscussionController;
use App\Http\Controllers\ElementController;
use App\Http\Controllers\EntiteController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\PusherAuthController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;


Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/refresh', [AuthController::class,'refreshToken']);
Route::post('/auth/logout', [AuthController::class, 'logout']);


Route::get('/users/stats', [UserController::class, 'getUserStats']);
Route::get('/users', [UserController::class, 'ListUsers']);
Route::post('users', [UserController::class, 'ajouterUtilisateur']);
Route::get('/users/{id}', [UserController::class, 'afficherUtilisateur']);
Route::put('/users/{id}', [UserController::class, 'modifierUtilisateur']);
Route::patch('users/{id}/activer', [UserController::class, 'activerUtilisateur']);
Route::patch('users/{id}/desactiver', [UserController::class, 'desactiverUtilisateur']);
Route::delete('/users/{id}', [UserController::class, 'supprimerUtilisateur']);
Route::get('/users/{id}', [UserController::class, 'showUsers']);

Route::middleware('auth:api')->group(function () {
    Route::post('/users/dlgas', [UserController::class, 'ajouterDlgas']);

});

Route::post('/entites', [EntiteController::class, 'ajoutEntite']);
Route::get('/entites', [EntiteController::class, 'ListEntites']);
Route::delete('entites/{id}/force', [EntiteController::class, 'supprimerEntiteForce']);
Route::put('entites/{id}', [EntiteController::class, 'modifierEntite']);

Route::middleware('auth:api')->group(function () {
    Route::post('users', [UserController::class, 'ajouterUtilisateur']);

    Route::get('entites/{id}', [EntiteController::class, 'showHierarchy']);

    Route::get('/mes-sous-entites', [EntiteController::class, 'mesSousEntites']);

    Route::get('/entites/{id}/sous-entites',[EntiteController::class, 'sousEntites']);
});


Route::post('/elements', [ElementController::class, 'ajouterElement']);
Route::get('/elements', [ElementController::class, 'listerElement']);
Route::delete('/elements/{id}', [ElementController::class, 'suppElement']);
Route::put('/elements/{id}', [ElementController::class, 'modifierElement']);


Route::get('/tickets/dlgas', [TicketController::class, 'listerTicketsDlga']);


Route::middleware('auth:api')->group(function () {
    Route::post('/tickets', [TicketController::class, 'ajouterTicket']);
});
Route::middleware('auth:api')->group(function () {
    Route::get('tickets/statistiques', [TicketController::class, 'statistiques']);
    Route::get('/tickets/recents',[TicketController::class,'ticketsRecents']);
    Route::get('/dashboard/activites', [TicketController::class,'activitesRecentes']);
        Route::get('tickets', [TicketController::class, 'listerTickets']);
    Route::get('tickets/{id}', [TicketController::class, 'afficherTicket']);
    Route::get('/tickets/recents',[TicketController::class,'ticketsRecents']);

});
Route::get('/dashboard/repartition-statuts', [TicketController::class,'repartitionStatuts']);
Route::middleware('auth:api')->put('tickets/{id}', [TicketController::class, 'modifierTicket']);
Route::get('/dashboard/evolution-tickets', [TicketController::class,'evolutionTickets']);
Route::delete('/tickets/{id}', [TicketController::class, 'supprimerTicket']);
Route::get('/tickets/dlgas', [TicketController::class, 'listerTicketsDlga']);



Route::middleware('auth:api')->group(function () {
    Route::post('/discussions', [DiscussionController::class, 'envoyerMessage']);
    Route::get('/tickets/{ticketId}/discussions', [DiscussionController::class, 'index']);
    Route::post('/broadcasting/auth', [PusherAuthController::class, 'authenticate']);
    Route::post('/discussions/{discussion}/accepter', [DiscussionController::class, 'accepterSolution']);
    Route::post('/discussions/{discussion}/refuser', [DiscussionController::class, 'refuserSolution']);
});
Route::get('/tickets/verifier-sla/{id}', [TicketController::class, 'verifierSLA']);







Route::middleware('auth:api')->get('/notifications', [NotificationsController::class, 'mesNotifications']);

Route::middleware('auth:api')->group(function (){

    Route::get('/dashboard/statistiques', [DashboardController::class, 'statistiques']);
    Route::get('/dashboard/repartition-statuts', [DashboardController::class, 'repartitionStatuts']);
    Route::get('/dashboard/evolution-tickets', [DashboardController::class, 'evolutionTickets']);

    Route::get('/dashboard/statistiques', [TicketController::class,'statistiquesGenerales']);

    Route::get('/dashboard/activite-recente', [DashboardController::class,'activiteRecente']);

    Route::get('/dashboard/activite-utilisateurs', [DashboardController::class,'activiteUtilisateurs']);

    Route::get('/dashboard/tickets-recents', [DashboardController::class,'ticketsRecents']);

});



Route::middleware('auth:api')->group(function () {

    Route::get('/analytic/vue-ensemble',[AnalyticController::class, 'vueEnsemble']);

    Route::get('/analytic/tickets/kpis', [AnalyticController::class,'ticketsKPIs',]);

    Route::get('/analytic/tickets/evolution',[AnalyticController::class, 'ticketsEvolution']);

    Route::get('/analytic/tickets/repartition',[AnalyticController::class, 'ticketsRepartition']);

    Route::get('/analytic/tickets/par-entite',[AnalyticController::class, 'ticketsParEntite']);

    Route::get('/analytic/tickets/par-categorie',[AnalyticController::class, 'ticketsParCategorie']);

    Route::get('/analytic/tickets/par-priorite',[AnalyticController::class, 'ticketsParPriorite']);

    Route::get('/analytic/tickets/par-statut',[AnalyticController::class, 'ticketsParStatut']);

    Route::get('/analytic/tickets/sla',[AnalyticController::class, 'ticketsSLA']);

    Route::get('/analytic/tickets/delais',[AnalyticController::class, 'ticketsDelais']);

    Route::get('/analytic/tickets/top-agents',[AnalyticController::class, 'topAgents']);

    Route::get('/analytic/tickets/top-createurs',[AnalyticController::class, 'topCreateurs']);

    Route::get('/analytic/utilisateurs/kpis', [AnalyticController::class,'utilisateursKPIs',]);

    Route::get('/analytic/utilisateurs/par-profil', [AnalyticController::class,'utilisateursParProfil',]);

    Route::get('/analytic/utilisateurs/par-entite', [AnalyticController::class,'utilisateursParEntite',]);

    Route::get('/analytic/utilisateurs/actifs', [AnalyticController::class,'utilisateursActifs',]);

    Route::get('/analytic/utilisateurs/evolution', [AnalyticController::class,'utilisateursEvolution',]);

    Route::get('/analytic/entites/kpis', [AnalyticController::class,'entitesKPIs',]);

    Route::get('/analytic/entites/performance', [AnalyticController::class,'entitesPerformance',]);

    Route::get('/analytic/entites/par-type', [AnalyticController::class,'entitesParType',]);

    Route::get('/analytic/entites/top', [AnalyticController::class,'topEntites',]);


    Route::get('/analytic/elements/kpis', [AnalyticController::class,'elementsKPIs',]);

    Route::get('/analytic/elements/par-type', [AnalyticController::class,'elementsParType',]);

    Route::get('/analytic/elements/par-etat', [AnalyticController::class,'elementsParEtat',]);

    Route::get('/analytic/elements/defectueux', [AnalyticController::class,'elementsDefectueux',]);

    Route::get('analytic/elements/par-entite', [AnalyticController::class,'elementsParEntite',]);

    Route::get('/analytic/activite/kpis', [AnalyticController::class,'activiteKPIs',]);

    Route::get('/analytic/activite/recente',[AnalyticController::class, 'activiteRecente']);

    Route::get('/analytic/activite/par-action',[AnalyticController::class, 'activiteParAction']);

    Route::get('analytic/activite/par-heure',[AnalyticController::class, 'activiteParHeure']);


Route::get('/analytic/categories', [AnalyticController::class, 'categories']);

Route::get('/analytic/sources-demande', [AnalyticController::class, 'sourcesDemande']);

Route::get('/analytic/performance-globale', [AnalyticController::class, 'performanceGlobale']);

Route::get('/analytic/alertes', [AnalyticController::class, 'alertes']);


});


