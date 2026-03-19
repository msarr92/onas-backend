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

    // private function generateUsernameAndEmail(string $nom, string $prenom): array
    // {
    //     // Nettoyage (supprime caractères spéciaux)
    //     $nom = strtolower(preg_replace('/[^a-z]/', '', $nom));
    //     $prenom = strtolower(preg_replace('/[^a-z]/', '', $prenom));

    //     $prenomPart = substr($prenom, 0, 3);

    //     $baseUsername = $nom . '.' . $prenomPart;
    //     $username = $baseUsername;
    //     $counter = 1;

    //     // Vérifie unicité
    //     while (\App\Models\User::where('username', $username)->exists()) {
    //         $username = $baseUsername . $counter;
    //         $counter++;
    //     }

    //     $email = $username . '@onas.sn';

    //     return [
    //         'username' => $username,
    //         'email' => $email,
    //     ];
    // }


    // private function generateUsername(string $nom, string $prenom): string
    // {
    //     $nom = strtolower(preg_replace('/[^a-z]/', '', $nom));
    //     $prenom = strtolower(preg_replace('/[^a-z]/', '', $prenom));

    //     $prenomPart = substr($prenom, 0, 3);

    //     $baseUsername = $nom . '.' . $prenomPart;
    //     $username = $baseUsername;
    //     $counter = 1;

    //     while (\App\Models\User::where('username', $username)->exists()) {
    //         $username = $baseUsername . $counter;
    //         $counter++;
    //     }

    //     return $username;
    // }


    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nom' => 'required|string|min:2|max:100',
            'prenom' => 'required|string|min:2|max:100',
            'email' => 'nullable|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'profil' => 'required|in:superadmin,selfservice,backoffice',
            'entite_id' => 'required|integer|exists:entites,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {

            $data = $validator->validated();



            $user = DB::transaction(function () use ($data) {
                return User::create([
                    'nom' => $data['nom'],
                    'prenom' => $data['prenom'],
                    'email' => $data['email'],
                    'username' => $data['username'],
                    'profil' => $data['profil'],
                    'entite_id' => $data['entite_id'],
                    'password' => Hash::make($data['password']),
                    'derniere_connexion' => null,
                    'statut' => 'actif'
                ]);
            });

            $token = auth()->guard('api')->login($user);

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur créé avec succès.',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'nom' => $user->nom,
                        'prenom' => $user->prenom,
                        'email' => $user->email,
                        'username' => $user->username,
                        'profil' => $user->profil,
                        'entite_id' => $user->entite_id,
                        'statut' => $user->statut,
                    ],
                    'token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl') * 60
                ]
            ], 201);
        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur interne serveur.'
            ], 500);
        }
    }

   public function refreshToken(): JsonResponse
{
    try {
        // Tenter de rafraîchir le token
        $newToken = auth('api')->refresh();

        // Récupérer l'utilisateur actuel
        $user = auth('api')->user();

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

    protected function respondWithToken($token): JsonResponse
    {
        $user = auth('api')->user();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'nom' => $user->nom,
                    'prenom' => $user->prenom,
                    'username' => $user->username,
                    'email' => $user->email,
                    'profil' => $user->profil,
                    'entite_id' => $user->entite_id,
                    'statut' => $user->statut,
                    'telephone' => $user->telephone,
                    'entite' => [
                        'id' => $user->entite?->id,
                        'nom' => $user->entite?->nom
                    ]
                ],

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
