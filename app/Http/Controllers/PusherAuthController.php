<?php
// app/Http/Controllers/PusherAuthController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PusherAuthController extends Controller
{
    /**
     * Authentifier un canal privé Pusher
     */
    public function authenticate(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non authentifié'
                ], 401);
            }

            $socketId = $request->socket_id;
            $channelName = $request->channel_name;

            // Vérifier que l'utilisateur a accès au canal
            if (str_starts_with($channelName, 'private-ticket.')) {
                $ticketId = explode('.', $channelName)[1] ?? null;

                if (!$ticketId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Canal invalide'
                    ], 400);
                }

                // Ici vous pouvez ajouter des vérifications supplémentaires
                // Par exemple, vérifier que l'utilisateur a accès à ce ticket
            }

            // Générer la signature d'authentification
            $pusher = new \Pusher\Pusher(
                config('broadcasting.connections.pusher.key'),
                config('broadcasting.connections.pusher.secret'),
                config('broadcasting.connections.pusher.app_id'),
                config('broadcasting.connections.pusher.options')
            );

           $auth = $pusher->socket_auth($channelName, $socketId);

            // Décoder la chaîne JSON renvoyée par Pusher pour que Laravel la comprenne
            $authData = json_decode($auth, true);

        return response()->json($authData);

        } catch (\Throwable $e) {
            Log::error('Erreur auth Pusher: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur d\'authentification',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
