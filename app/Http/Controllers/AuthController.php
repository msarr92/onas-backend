<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class AuthController extends Controller
{
    public function refreshToken(): JsonResponse
    {
        try {
            // 1. Rafraîchir le token
            $newToken = auth('api')->refresh();

            if (! $newToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de rafraîchir le token.',
                ], 401);
            }

            // 2. Essayer de récupérer l'utilisateur via le nouveau token
            $user = auth('api')->user();

            // ✅ 3. Si user() retourne null, utiliser setToken() puis réessayer
            if (! $user) {
                \Log::warning('⚠️ [refreshToken] user() null, tentative via setToken');

                // Réinjecter le nouveau token dans le guard
                auth('api')->setToken($newToken);
                $user = auth('api')->user();
            }

            // ✅ 4. Si toujours null, chercher l'user via le payload du token
            if (! $user) {
                \Log::warning('⚠️ [refreshToken] user() toujours null, décodage manuel du token');

                $payload = auth('api')->getPayload();
                $userId = $payload->get('sub');

                if ($userId) {
                    $user = \App\Models\User::find($userId);
                }
            }

            // ✅ 5. Si toujours null → vraiment un problème
            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur introuvable. Veuillez vous reconnecter.',
                ], 401);
            }

            // ✅ 6. Charger les relations
            $user->load('entites');

            return response()->json([
                'success' => true,
                'data' => [
                    'access_token' => $newToken,
                    'token_type' => 'bearer',
                    'expires_in' => auth('api')->factory()->getTTL() * 60,
                    'user' => $user,
                ],
            ]);

        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            \Log::warning('⚠️ [refreshToken] Token expiré: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Token expiré, veuillez vous reconnecter.',
            ], 401);

        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            \Log::warning('⚠️ [refreshToken] Token invalide: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Token invalide, veuillez vous reconnecter.',
            ], 401);

        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            \Log::warning('⚠️ [refreshToken] Erreur JWT: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur JWT.',
            ], 401);

        } catch (\Throwable $e) {
            \Log::error('❌ [refreshToken] Erreur', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du rafraîchissement.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'telephone' => 'nullable|string|max:20',

            'profil' => [
                'required',
                Rule::in([
                    'superadmin',
                    'admin_selfservice',
                    'selfservice',
                    'admin_backoffice',
                    'backoffice',
                    'admin_dlgas',
                    'dlgas',
                ]),
            ],

            // Obligatoire sauf pour le superadmin
            'entites' => Rule::requiredIf($request->profil !== 'superadmin'),
            'entites.*' => 'exists:entites,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {

            // Entité principale (peut être null pour le superadmin)
            $entitePrincipale = null;

            if ($request->filled('entites')) {
                $entitePrincipale = $request->entites[0];
            }

            $user = User::create([
                'nom' => $request->nom,
                'prenom' => $request->prenom,
                'username' => $request->username,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'telephone' => $request->telephone,
                'profil' => $request->profil,
                'statut' => 'actif',
                'entite_id' => $entitePrincipale,
            ]);

            // Associer les entités uniquement si elles existent
            if ($request->filled('entites')) {
                $user->entites()->sync($request->entites);
            }

            DB::commit();

            // Génération du JWT
            $token = auth('api')->login($user);

            // Recharger les relations
            $user->load('entites:id,nom');

            return response()->json([
                'success' => true,
                'message' => 'Inscription réussie.',
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => auth('api')->factory()->getTTL() * 60,
                'data' => [
                    'id' => $user->id,
                    'nom' => $user->nom,
                    'prenom' => $user->prenom,
                    'username' => $user->username,
                    'email' => $user->email,
                    'telephone' => $user->telephone,
                    'profil' => $user->profil,
                    'statut' => $user->statut,
                    'entites' => $user->entites,
                ],
            ], 201);

        } catch (Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'inscription.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {

            $credentials = $validator->validated();

            if (! $token = auth('api')->attempt($credentials)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifiants incorrects.',
                ], 401);
            }

            $user = auth('api')->user();
            // Charger les entités de l'utilisateur (relation many-to-many)
            $user->load('entites');

            $user->update([
                'derniere_connexion' => now(),
            ]);

            return $this->respondWithToken($token);
        } catch (Throwable $e) {
            \Log::error('❌ [login] Erreur', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur interne serveur.',
                'error' => $e->getMessage(),
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
                'nom' => $user->entite->nom,
            ] : (! empty($entitesList) ? $entitesList[0] : null),

            // TOUTES les entités (depuis la table entite_user)
            'entites' => $entitesList,

            'derniere_connexion' => $user->derniere_connexion,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
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
                    'refresh_ttl' => config('jwt.refresh_ttl') * 60,
                ],
            ],
        ]);
    }

    public function logout(): JsonResponse
    {
        try {

            auth()->guard('api')->logout();

            return response()->json([
                'success' => true,
                'message' => 'Déconnexion réussie.',
            ]);
        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la déconnexion.',
            ], 500);
        }
    }


}
