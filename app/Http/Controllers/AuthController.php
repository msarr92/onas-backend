<?php

namespace App\Http\Controllers;


use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class AuthController extends Controller
{



   public function refreshToken(): JsonResponse
{
    try {
        // Tenter de rafraîchir le token
        $newToken = auth('api')->refresh();

        // Récupérer l'utilisateur actuel
        $user = auth('api')->user();

         $user->load('entites');

        return response()->json([
            'success' => true,
            'data' => [
                'access_token' => $newToken,
                'token_type' => 'bearer',
                'expires_in' => auth('api')->factory()->getTTL() * 60,
                'user' => $user
            ]
        ]);

    } catch (Throwable $e) {
        return response()->json([
            'success' => false,
            'message' => 'Token invalide ou expiré.',
            'error' => $e->getMessage()
        ], 401);
    }
}


    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {

            $credentials = $validator->validated();

            if (!$token = auth('api')->attempt($credentials)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifiants incorrects.'
                ], 401);
            }

            $user = auth('api')->user();
             // Charger les entités de l'utilisateur (relation many-to-many)
            $user->load('entites');

            $user->update([
                'derniere_connexion' => now()
            ]);

            return $this->respondWithToken($token);
        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur interne serveur.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

   /**
     * Formater l'utilisateur avec toutes ses entités
     */
    private function formatUserWithEntities($user): array
    {
        // Formater la liste des entités (many-to-many)
        $entitesList = [];
        foreach ($user->entites as $entite) {
            $entitesList[] = [
                'id' => $entite->id,
                'nom' => $entite->nom,
                'code' => $entite->code ?? null,
                'type' => $entite->type ?? null,
                'description' => $entite->description ?? null,
                'parent_id' => $entite->parent_id ?? null,
                'is_principal' => ($entite->id == $user->entite_id),
                'created_at' => $entite->created_at,
                'updated_at' => $entite->updated_at,
            ];
        }

        return [
            'id' => $user->id,
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'username' => $user->username,
            'email' => $user->email,
            'profil' => $user->profil,
            'entite_id' => $user->entite_id,
            'statut' => $user->statut,
            'telephone' => $user->telephone,

            // Entité principale (pour compatibilité)
            'entite' => $user->entite ? [
                'id' => $user->entite->id,
                'nom' => $user->entite->nom
            ] : (!empty($entitesList) ? $entitesList[0] : null),

            // TOUTES les entités (depuis la table entite_user)
            'entites' => $entitesList,

            'derniere_connexion' => $user->derniere_connexion,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at
        ];
    }

    protected function respondWithToken($token): JsonResponse
    {
        $user = auth('api')->user();
        $user->load('entites');

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $this->formatUserWithEntities($user),
                'tokens' => [
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                    'expires_in' => auth('api')->factory()->getTTL() * 60,
                    'refresh_ttl' => config('jwt.refresh_ttl') * 60
                ]
            ]
        ]);
    }



    public function logout(): JsonResponse
    {
        try {

            auth()->guard('api')->logout();

            return response()->json([
                'success' => true,
                'message' => 'Déconnexion réussie.'
            ]);
        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la déconnexion.'
            ], 500);
        }
    }




}
