<?php

namespace Route\Api;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DiscussionController;
use App\Http\Controllers\ElementController;
use App\Http\Controllers\EntiteController;
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

// Route::post('/dlgas', [UserController::class, 'ajouterDLGA']);

Route::post('/entites', [EntiteController::class, 'ajoutEntite']);
Route::get('/entites', [EntiteController::class, 'ListEntites']);
Route::delete('entites/{id}/force', [EntiteController::class, 'supprimerEntiteForce']);
Route::put('entites/{id}', [EntiteController::class, 'modifierEntite']);
Route::middleware('auth:api')->group(function () {
    Route::get('entites/{id}', [EntiteController::class, 'showHierarchy']);
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
    Route::get('tickets', [TicketController::class, 'listerTickets']);
    Route::get('tickets/{id}', [TicketController::class, 'afficherTicket']);
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
});
Route::get('/tickets/verifier-sla/{id}', [TicketController::class, 'verifierSLA']);

//Route::middleware('auth:api')->get('/tickets/statistiques', [TicketController::class, 'statistiques']);

Route::get('/dashboard/statistiques', [TicketController::class,'statistiquesGenerales']);
