<?php
// app/Http/Controllers/DiscussionController.php

namespace App\Http\Controllers;

use App\Models\Discussion;
use App\Models\Tickets;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Events\MessageSent;
use App\Models\Discussions;
use Throwable;


class DiscussionController extends Controller
{
    /**
     * Récupérer les messages d'un ticket
     */
    public function index(int $ticketId): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non authentifié'
                ], 401);
            }

            $messages = Discussions::with(['utilisateur:id,nom,prenom', 'acceptedBy:id,nom,prenom', 'rejectedBy:id,nom,prenom'])
                ->where('ticket_id', $ticketId)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($message) {
                    return [
                        'id' => $message->id,
                        'message' => $message->message,
                        'type' => $message->type ?? '',
                        'user_id' => $message->user_id,
                        'user_nom' => $message->utilisateur ?
                            $message->utilisateur->prenom . ' ' . $message->utilisateur->nom :
                            'Système',
                        'created_at' => $message->created_at,
                        'accepted_at' => $message->accepted_at,
                        'accepted_by' => $message->accepted_by,
                        'accepted_by_name' => $message->acceptedBy ?
                            $message->acceptedBy->prenom . ' ' . $message->acceptedBy->nom :
                            null,
                        'rejected_at' => $message->rejected_at,
                        'rejected_by' => $message->rejected_by,
                        'rejected_by_name' => $message->rejectedBy ?
                            $message->rejectedBy->prenom . ' ' . $message->rejectedBy->nom :
                            null,
                        'rejection_reason' => $message->rejection_reason,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $messages
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des messages',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Envoyer un message
     */
    public function envoyerMessage(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'ticket_id' => 'required|exists:tickets,id',
                'message' => 'required|string|max:1000',
                'type' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non authentifié'
                ], 401);
            }

            DB::beginTransaction();

            // Récupérer le ticket
            $ticket = Tickets::find($request->ticket_id);

            // Vérifier si c'est un refus de solution
            $isRefusSolution = false;
            $motifRefus = null;

            // Détecter un refus de solution dans le message
            if (
                str_contains($request->message, 'refuse') ||
                str_contains($request->message, 'Refuse') ||
                str_contains($request->message, 'refus') ||
                str_contains($request->message, 'Refus') ||
                str_starts_with($request->message, '❌ Je refuse')
            ) {

                $isRefusSolution = true;

                // Extraire le motif du refus
                if (preg_match('/Motif\s*:\s*(.+)$/', $request->message, $matches)) {
                    $motifRefus = trim($matches[1]);
                } elseif (preg_match('/refuse.*:\s*(.+)$/i', $request->message, $matches)) {
                    $motifRefus = trim($matches[1]);
                } else {
                    $motifRefus = 'Aucun motif spécifié';
                }
            }

            // Création du message
            $discussion = Discussions::create([
                'ticket_id' => $request->ticket_id,
                'user_id' => $user->id,
                'message' => $request->message,
                'type' => $request->type ?? '',
                'statut_solution' => $isRefusSolution ? 'refusee' : null
            ]);

            // Déterminer le statut du ticket
            if ($isRefusSolution) {
                // C'est un refus de solution
                $ticket->statut = 'en_cours';
                $ticket->statut_solution = 'refusee';
                $ticket->save();
            }
            // Si une solution avait été refusée précédemment
            elseif ($ticket->statut_solution === 'refusee') {
                $ticket->statut = 'en_cours';
                $ticket->save();
            }
            // Sinon, passer en résolu
            else {
                $ticket->statut = 'resolu';
                $ticket->save();
            }

            // Charger la relation utilisateur
            $discussion->load('utilisateur');

            // Diffusion événement temps réel
            broadcast(new MessageSent($discussion))->toOthers();

            DB::commit();

            $responseData = [
                'id' => $discussion->id,
                'message' => $discussion->message,
                'type' => $discussion->type,
                'user_id' => $discussion->user_id,
                'user_nom' => $discussion->utilisateur ?
                    $discussion->utilisateur->prenom . ' ' . $discussion->utilisateur->nom :
                    'Système',
                'created_at' => $discussion->created_at
            ];

            // Ajouter des informations supplémentaires en cas de refus
            if ($isRefusSolution) {
                $responseData['refus_info'] = [
                    'motif' => $motifRefus,
                    'statut_solution' => 'refusee',
                    'ticket_statut' => $ticket->statut
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Message envoyé avec succès',
                'data' => $responseData
            ], 201);
        } catch (Throwable $e) {
            DB::rollBack();

            // \Log::error('Erreur envoi message', [
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi du message',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // app/Http/Controllers/DiscussionController.php
    public function accepterSolution(Request $request, $discussionId): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non authentifié'
                ], 401);
            }

            DB::beginTransaction();

            $discussion = Discussions::findOrFail($discussionId);
            $ticket = Tickets::findOrFail($discussion->ticket_id);

            // Mettre à jour le message comme accepté
            $discussion->accepted_at = now();
            $discussion->accepted_by = $user->id;
            $discussion->save();

            // Mettre à jour le statut du ticket
            $ticket->statut = 'resolu';
            $ticket->statut_solution = 'acceptee';
            $ticket->save();

            // Créer un message d'acceptation
            $acceptMessage = Discussions::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'message' => "✅ Solution acceptée par " . $user->prenom . " " . $user->nom,
                'type' => 'acceptation'
            ]);

            $acceptMessage->load('utilisateur');

            // Diffusion événement temps réel
            broadcast(new MessageSent($acceptMessage))->toOthers();

            DB::commit();

            // Charger les relations
            $discussion->load('acceptedBy', 'utilisateur');

            return response()->json([
                'success' => true,
                'message' => 'Solution acceptée avec succès',
                'data' => [
                    'discussion' => [
                        'id' => $discussion->id,
                        'accepted_at' => $discussion->accepted_at,
                        'accepted_by' => $discussion->accepted_by,
                        'accepted_by_name' => $discussion->acceptedBy ?
                            $discussion->acceptedBy->prenom . ' ' . $discussion->acceptedBy->nom :
                            null
                    ],
                    'ticket' => [
                        'id' => $ticket->id,
                        'statut' => $ticket->statut,
                        'statut_solution' => $ticket->statut_solution
                    ],
                    'message' => [
                        'id' => $acceptMessage->id,
                        'message' => $acceptMessage->message,
                        'type' => $acceptMessage->type,
                        'user_id' => $acceptMessage->user_id,
                        'user_nom' => $acceptMessage->utilisateur ?
                            $acceptMessage->utilisateur->prenom . ' ' . $acceptMessage->utilisateur->nom :
                            'Système',
                        'created_at' => $acceptMessage->created_at
                    ]
                ]
            ], 201);
        } catch (Throwable $e) {
            DB::rollBack();
            //\Log::error('Erreur acceptation solution', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'acceptation de la solution',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function refuserSolution(Request $request, $discussionId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'motif' => 'required|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non authentifié'
                ], 401);
            }

            DB::beginTransaction();

            $discussion = Discussions::findOrFail($discussionId);
            $ticket = Tickets::findOrFail($discussion->ticket_id);

            // Mettre à jour le message comme refusé
            $discussion->rejected_at = now();
            $discussion->rejected_by = $user->id;
            $discussion->rejection_reason = $request->motif;
            $discussion->save();

            // Mettre à jour le statut du ticket
            $ticket->statut = 'en_cours';
            $ticket->statut_solution = 'refusee';
            $ticket->save();

            // Créer un message de refus
            $refusMessage = Discussions::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'message' => "❌ Solution refusée par " . $user->prenom . " " . $user->nom . "\nMotif : " . $request->motif,
                'type' => 'refus'
            ]);

            $refusMessage->load('utilisateur');

            // Diffusion événement temps réel
            broadcast(new MessageSent($refusMessage))->toOthers();

            DB::commit();

            // Charger les relations
            $discussion->load('rejectedBy', 'utilisateur');

            return response()->json([
                'success' => true,
                'message' => 'Solution refusée avec succès',
                'data' => [
                    'discussion' => [
                        'id' => $discussion->id,
                        'rejected_at' => $discussion->rejected_at,
                        'rejected_by' => $discussion->rejected_by,
                        'rejected_by_name' => $discussion->rejectedBy ?
                            $discussion->rejectedBy->prenom . ' ' . $discussion->rejectedBy->nom :
                            null,
                        'rejection_reason' => $discussion->rejection_reason
                    ],
                    'ticket' => [
                        'id' => $ticket->id,
                        'statut' => $ticket->statut,
                        'statut_solution' => $ticket->statut_solution
                    ],
                    'message' => [
                        'id' => $refusMessage->id,
                        'message' => $refusMessage->message,
                        'type' => $refusMessage->type,
                        'user_id' => $refusMessage->user_id,
                        'user_nom' => $refusMessage->utilisateur ?
                            $refusMessage->utilisateur->prenom . ' ' . $refusMessage->utilisateur->nom :
                            'Système',
                        'created_at' => $refusMessage->created_at
                    ]
                ]
            ], 201);
        } catch (Throwable $e) {
            DB::rollBack();
            // \Log::error('Erreur refus solution', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du refus de la solution',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
