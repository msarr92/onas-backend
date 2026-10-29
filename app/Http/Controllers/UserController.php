<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class UserController extends Controller
{
    /**
     * Liste des utilisateurs avec statut en ligne et dernière connexion
     */
    public function ListUsers(Request $request): JsonResponse
    {
        try {

            $user = auth('api')->user();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.',
                ], 401);
            }

            $query = User::with([
                'entites:id,nom',
            ])
                ->select(
                    'id',
                    'nom',
                    'prenom',
                    'username',
                    'email',
                    'telephone',
                    'profil',
                    'statut',
                    'created_at',
                    'derniere_connexion'
                );

            $perPage = $request->get('per_page', 5);

            $users = $query
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            // récupérer les profils existants
            $profils = User::select('profil')
                ->distinct()
                ->pluck('profil');

            // récupérer les utilisateurs dlgas
            $usersDlga = User::with('entites:id,nom')
                ->select(
                    'id',
                    'nom',
                    'prenom',
                    'username',
                    'email'
                )
                ->where('profil', 'consultation')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Liste des utilisateurs récupérée avec succès.',
                'data' => [
                    'users' => $users,
                    'profils' => $profils,
                    'users_dlgas' => $usersDlga,
                ],
            ]);
        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des utilisateurs.',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    /**
     * Récupérer un utilisateur spécifique
     */
    public function showUsers($id)
    {
        try {
            // Charger l'utilisateur avec ses entités (many-to-many)
            $user = User::with(['entites' => function ($query) {
                $query->select('entites.id', 'entites.nom');
            }])
                ->select(
                    'id',
                    'nom',
                    'prenom',
                    'username',
                    'email',
                    'profil',
                    'telephone',
                    'created_at',
                    'updated_at',
                    'derniere_connexion',
                    'statut'
                )
                ->find($id);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non trouvé',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $user,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'utilisateur',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mettre à jour le statut d'un utilisateur
     */
    public function updateUsers(Request $request, $id)
    {
        try {
            $request->validate([
                'statut' => 'required|in:actif,inactif,bloque',
            ]);

            $user = User::find($id);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non trouvé',
                ], 404);
            }

            $user->statut = $request->statut;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Statut mis à jour avec succès',
                'data' => [
                    'id' => $user->id,
                    'statut' => $user->statut,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du statut',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Supprimer un utilisateur
     */
    public function suppUsers($id)
    {
        try {
            $user = User::find($id);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non trouvé',
                ], 404);
            }

            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur supprimé avec succès',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de l\'utilisateur',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Récupère les statistiques des utilisateurs
     * Total et nouveaux inscrits ce mois
     */
    public function getUserStats(): JsonResponse
    {
        try {
            // Total des utilisateurs
            $total = User::count();

            // Nouveaux utilisateurs ce mois
            $debutMois = Carbon::now()->startOfMonth();
            $nouveauxCeMois = User::where('created_at', '>=', $debutMois)->count();

            // Utilisateurs actifs (dernière connexion <= 30 jours)
            $dateLimite = Carbon::now()->subDays(30);
            $actifs = User::where('statut', 'actif')->count();

            // Répartition par profil (adaptez selon vos profils)
            $admins = User::where('profil', 'superadmin')->count();
            $backoffice = User::where('profil', 'backoffice')->count();
            $selfservice = User::where('profil', 'selfservice')->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'nouveaux_ce_mois' => $nouveauxCeMois,
                    'actifs' => $actifs,
                    'inactifs' => $total - $actifs,
                    'admins' => $admins,
                    'backoffice' => $backoffice,
                    'selfservice' => $selfservice,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function ajouterUtilisateur(Request $request): JsonResponse 
    {
        // ============================================================
        // 1. Validation des données
        // ============================================================

        $validator = Validator::make($request->all(), [

            'nom' => [
                'required',
                'string',
                'max:255',
            ],

            'prenom' => [
                'required',
                'string',
                'max:255',
            ],

            'username' => [
                'required',
                'string',
                'max:255',
                'unique:users,username',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'password' => [
                'required',
                'string',
                'min:6',
                'confirmed',
            ],

            'profil' => [
                'required',
                'in:superadmin,selfservice,backoffice,dlgas,admin_selfservice,admin_backoffice,admin_dlgas',
            ],

            'entites' => [
                'required',
                'array',
                'min:1',
            ],

            'entites.*' => [
                'required',
                'integer',
                'exists:entites,id',
            ],

            'telephone' => [
                'required',
                'string',
                'max:30',
            ],
        ]);

        // ============================================================
        // 2. Vérifier les erreurs
        // ============================================================

        if ($validator->fails()) {

            return response()->json([
                'success' => false,
                'message' => 'Les données fournies sont invalides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {

            // ========================================================
            // 3. Début de la transaction
            // ========================================================

            DB::beginTransaction();

            // ========================================================
            // 4. Création de l'utilisateur
            // ========================================================

            $user = User::create([

                'nom' => $request->nom,

                'prenom' => $request->prenom,

                'username' => $request->username,

                'email' => $request->email,

                'password' => Hash::make(
                    $request->password
                ),

                // Profil choisi dans le formulaire
                'profil' => $request->profil,

                'telephone' => $request->telephone,

                'statut' => 'actif',
            ]);

            // ========================================================
            // 5. Association aux entités
            // ========================================================

            $user->entites()->sync(
                $request->entites
            );

            // ========================================================
            // 6. Valider la transaction
            // ========================================================

            DB::commit();

            // ========================================================
            // 7. Charger les entités dans la réponse
            // ========================================================

            $user->load([
                'entites:id,nom',
            ]);

            // ========================================================
            // 8. Réponse
            // ========================================================

            return response()->json([

                'success' => true,

                'message' => 'Utilisateur créé avec succès.',

                'data' => $user,

            ], 201);

        } catch (\Throwable $e) {

            // ========================================================
            // 9. Annuler la transaction en cas d'erreur
            // ========================================================

            DB::rollBack();

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors de la création de l’utilisateur.',

                'error' => $e->getMessage(),

            ], 500);
        }
    }

    public function afficherUtilisateur($id): JsonResponse
    {
        try {

            $user = User::with('entite')->find($id);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non trouvé.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Détails utilisateur récupérés avec succès.',
                'data' => [
                    'id' => $user->id,
                    'nom' => $user->nom,
                    'prenom' => $user->prenom,
                    'username' => $user->username,
                    'email' => $user->email,
                    'telephone' => $user->telephone,
                    'profil' => $user->profil,
                    'statut' => $user->statut,
                    'entite' => [
                        'id' => $user->entite?->id,
                        'nom' => $user->entite?->nom,
                    ],
                    'derniere_connexion' => $user->derniere_connexion,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ],
            ]);
        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des détails utilisateur.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function modifierUtilisateur(Request $request, int $id): JsonResponse
    {
        try {

            $authUser = auth('api')->user();

            if (! $authUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.',
                ], 401);
            }

            // Seuls les administrateurs peuvent modifier
            if (! in_array($authUser->profil, [
                'superadmin',
                // 'admin_selfservice',
                // 'admin_backoffice',
                // 'admin_dlgas'
            ])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé.',
                ], 403);
            }

            $user = User::find($id);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non trouvé.',
                ], 404);
            }

            $validator = Validator::make($request->all(), [

                'nom' => 'sometimes|required|string|max:255',

                'prenom' => 'sometimes|required|string|max:255',

                'telephone' => 'sometimes|nullable|string|max:20',

                'username' => [
                    'sometimes',
                    'required',
                    'string',
                    Rule::unique('users', 'username')->ignore($user->id),
                ],

                'profil' => [
                    'sometimes',
                    'required',
                    Rule::in([
                        'superadmin',
                        'selfservice',
                        'backoffice',
                        'consultation',
                        'dlgas',
                        'admin_selfservice',
                        'admin_backoffice',
                        'admin_dlgas',
                    ]),
                ],

                // tableau des entités
                'entites' => 'nullable|array',

                'entites.*' => 'exists:entites,id',

                'statut' => 'sometimes|required|in:actif,inactif',

                'password' => 'nullable|string|min:6|confirmed',

            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Vérification SuperAdmin
            |--------------------------------------------------------------------------
            */

            $profil = $request->profil ?? $user->profil;

            if ($profil !== 'superadmin') {

                if (
                    ! $request->has('entites') &&
                    empty($user->entite_id)
                ) {

                    return response()->json([
                        'success' => false,
                        'message' => 'Une entité est obligatoire pour ce profil.',
                    ], 422);
                }

                if (
                    $request->has('entites') &&
                    count($request->entites) == 0
                ) {

                    return response()->json([
                        'success' => false,
                        'message' => 'Une entité est obligatoire pour ce profil.',
                    ], 422);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Mise à jour
            |--------------------------------------------------------------------------
            */

            if ($request->filled('nom')) {
                $user->nom = $request->nom;
            }

            if ($request->filled('prenom')) {
                $user->prenom = $request->prenom;
            }

            if ($request->filled('username')) {
                $user->username = $request->username;
            }

            if ($request->has('telephone')) {
                $user->telephone = $request->telephone;
            }

            if ($request->filled('profil')) {
                $user->profil = $request->profil;
            }

            if ($request->filled('statut')) {
                $user->statut = $request->statut;
            }

            if ($request->filled('password')) {
                $user->password = Hash::make($request->password);
            }

            /*
            |--------------------------------------------------------------------------
            | Gestion des entités
            |--------------------------------------------------------------------------
            */

            if ($request->has('entites')) {

                if ($profil == 'superadmin') {

                    // un superadmin peut ne pas avoir d'entité
                    if (count($request->entites) > 0) {

                        $user->entite_id = $request->entites[0];

                        $user->save();

                        $user->entites()->sync($request->entites);

                    } else {

                        $user->entite_id = null;

                        $user->save();

                        $user->entites()->detach();
                    }

                } else {

                    $user->entite_id = $request->entites[0];

                    $user->save();

                    $user->entites()->sync($request->entites);
                }

            } else {

                $user->save();
            }

            $user->load('entites');

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur modifié avec succès.',
                'data' => [
                    'id' => $user->id,
                    'nom' => $user->nom,
                    'prenom' => $user->prenom,
                    'username' => $user->username,
                    'email' => $user->email,
                    'telephone' => $user->telephone,
                    'profil' => $user->profil,
                    'statut' => $user->statut,
                    'entite_id' => $user->entite_id,
                    'entites' => $user->entites->map(function ($entite) {
                        return [
                            'id' => $entite->id,
                            'nom' => $entite->nom,
                        ];
                    }),
                    'updated_at' => $user->updated_at,
                ],
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification.',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);

        }
    }

    public function activerUtilisateur(int $id): JsonResponse
    {
        try {

            $user = User::find($id);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non trouvé.',
                ], 404);
            }

            $user->update([
                'statut' => 'actif',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur activé avec succès.',
                'data' => [
                    'id' => $user->id,
                    'statut' => $user->statut,
                ],
            ]);
        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'activation.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function desactiverUtilisateur(int $id): JsonResponse
    {
        try {

            $user = User::find($id);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non trouvé.',
                ], 404);
            }

            $user->update([
                'statut' => 'inactif',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur désactivé avec succès.',
                'data' => [
                    'id' => $user->id,
                    'statut' => $user->statut,
                ],
            ]);
        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la désactivation.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function ajouterDlgas(Request $request): JsonResponse
    {
        try {

            // ============================================================
            // 1. Validation des données reçues
            // ============================================================

            $validator = Validator::make($request->all(), [

                'nom' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'prenom' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'username' => [
                    'required',
                    'string',
                    'max:255',
                    'unique:users,username',
                ],

                'email' => [
                    'required',
                    'email',
                    'max:255',
                    'unique:users,email',
                ],

                'password' => [
                    'required',
                    'string',
                    'min:6',
                    'confirmed',
                ],

                'telephone' => [
                    'nullable',
                    'string',
                    'max:30',
                ],
            ]);

            // ============================================================
            // 2. Vérifier les erreurs de validation
            // ============================================================

            if ($validator->fails()) {

                return response()->json([
                    'success' => false,
                    'message' => 'Les données fournies sont invalides.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // ============================================================
            // 3. Création de l'utilisateur
            // ============================================================

            $user = User::create([

                'nom' => $request->nom,

                'prenom' => $request->prenom,

                'username' => $request->username,

                'email' => $request->email,

                'password' => Hash::make(
                    $request->password
                ),

                // Le profil est imposé par cette fonction
                'profil' => 'dlgas',

                'telephone' => $request->telephone,

                'statut' => 'actif',

                'entite_id' => null,
            ]);

            // ============================================================
            // 4. Association de l'utilisateur à son entité
            // ============================================================

            $user->entites()->attach(
                $request->entite_id
            );

            // ============================================================
            // 5. Charger les informations utiles dans la réponse
            // ============================================================

            $user->load([
                'entites:id,nom',
            ]);

            // ============================================================
            // 6. Réponse
            // ============================================================

            return response()->json([
                'success' => true,

                'message' => 'Utilisateur DLGAS créé avec succès.',

                'data' => $user,

            ], 201);

        } catch (\Throwable $e) {

            // ============================================================
            // 7. Gestion des erreurs
            // ============================================================

            return response()->json([

                'success' => false,

                'message' => 'Une erreur est survenue lors de la création de l’utilisateur DLGAS.',

                'error' => $e->getMessage(),

            ], 500);
        }
    }

    public function supprimerUtilisateur($id): JsonResponse
    {
        try {
            $authUser = auth('api')->user();

            if (! $authUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.',
                ], 401);
            }

            $user = User::find($id);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur introuvable.',
                ], 404);
            }

            //  Empêcher suppression de soi-même (bonne pratique)
            if ($authUser->id == $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne pouvez pas supprimer votre propre compte.',
                ], 403);
            }

            DB::beginTransaction();

            //  Supprimer les relations avec entités (pivot)
            $user->entites()->detach();

            //  Supprimer les notifications liées (optionnel mais recommandé)
            $user->notifications()->delete();

            //  Supprimer l'utilisateur
            $user->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur supprimé avec succès.',
            ]);
        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de l’utilisateur.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
