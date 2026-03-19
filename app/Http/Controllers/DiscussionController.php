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

            $messages = Discussions::with('utilisateur:id,nom,prenom')
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
                        'created_at' => $message->created_at
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

            // Création du message
            $discussion = Discussions::create([
                'ticket_id' => $request->ticket_id,
                'user_id' => $user->id,
                'message' => $request->message,
                'type' => $request->type ?? ''
            ]);

            // 🔹 Mise à jour du statut du ticket
            Tickets::where('id', $request->ticket_id)
                ->update(['statut' => 'resolu']);

            // Charger la relation utilisateur
            $discussion->load('utilisateur');

            // Diffusion événement temps réel
            broadcast(new MessageSent($discussion))->toOthers();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Message envoyé avec succès',
                'data' => [
                    'id' => $discussion->id,
                    'message' => $discussion->message,
                    'type' => $discussion->type,
                    'user_id' => $discussion->user_id,
                    'user_nom' => $discussion->utilisateur ?
                        $discussion->utilisateur->prenom . ' ' . $discussion->utilisateur->nom :
                        'Système',
                    'created_at' => $discussion->created_at
                ]
            ], 201);

        } catch (Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi du message',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
