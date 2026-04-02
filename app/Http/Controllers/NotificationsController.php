<?php

namespace App\Http\Controllers;

use App\Models\Notifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class NotificationsController extends Controller
{
    public function mesNotifications(): JsonResponse
    {
        try {

            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            $notifications = Notifications::with('ticket')
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Liste des notifications.',
                'data' => $notifications
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des notifications.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
