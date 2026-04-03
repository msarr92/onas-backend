<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            $query = User::with([
                'entites:id,nom'
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
                    'users_dlgas' => $usersDlga
                ]
            ]);
        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des utilisateurs.',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
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
        $user = User::with(['entites' => function($query) {
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

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non trouvé'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $user
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la récupération de l\'utilisateur',
            'error' => $e->getMessage()
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
                'statut' => 'required|in:actif,inactif,bloque'
            ]);

            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non trouvé'
                ], 404);
            }

            $user->statut = $request->statut;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Statut mis à jour avec succès',
                'data' => [
                    'id' => $user->id,
                    'statut' => $user->statut
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du statut',
                'error' => $e->getMessage()
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

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non trouvé'
                ], 404);
            }

            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur supprimé avec succès'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de l\'utilisateur',
                'error' => $e->getMessage()
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
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function ajouterUtilisateur(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'username' => 'required|string|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'profil' => 'required|string',
            'entites' => 'required|array',              // tableau d'entite_id
            'entites.*' => 'exists:entites,id',        // chaque id doit exister
            'telephone' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $defaultEntiteId = $request->entites[0] ?? null;

            $user = User::create([
                'nom' => $request->nom,
                'prenom' => $request->prenom,
                'username' => $request->username,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'profil' => $request->profil,
                'telephone' => $request->telephone,
                'statut' => 'actif',
                'entite_id' => $defaultEntiteId, // ← Ajouter cette ligne
            ]);

            // 🔹 Associer les entités via la table pivot
            $user->entites()->sync($request->entites);

            return response()->json([
                'success' => true,
                'data' => $user,
                'message' => 'Utilisateur créé avec succès'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function afficherUtilisateur($id): JsonResponse
    {
        try {

            $user = User::with('entite')->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non trouvé.'
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
                    'updated_at' => $user->updated_at
                ]
            ]);
        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des détails utilisateur.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function modifierUtilisateur(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non trouvé.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nom' => 'sometimes|required|string|max:255',
            'prenom' => 'sometimes|required|string|max:255',
            'telephone' => 'sometimes|string|max:20',
            'profil' => 'sometimes|required|in:superadmin,selfservice,backoffice,consultation',
            'entite_id' => 'sometimes|required|exists:entites,id',
            'statut' => 'sometimes|required|in:actif,inactif',
            'password' => 'nullable|string|min:6|confirmed'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {

            // Mise à jour des champs modifiables
            if ($request->has('nom')) {
                $user->nom = $request->nom;
            }

            if ($request->has('prenom')) {
                $user->prenom = $request->prenom;
            }

            if ($request->has('telephone')) {
                $user->telephone = $request->telephone;
            }

            if ($request->has('profil')) {
                $user->profil = $request->profil;
            }

            if ($request->has('entite_id')) {
                $user->entite_id = $request->entite_id;
            }

            if ($request->has('statut')) {
                $user->statut = $request->statut;
            }

            // Mot de passe (optionnel)
            if ($request->filled('password')) {
                $user->password = Hash::make($request->password);
            }

            $user->save();

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
                    'updated_at' => $user->updated_at
                ]
            ]);
        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function activerUtilisateur(int $id): JsonResponse
    {
        try {

            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non trouvé.'
                ], 404);
            }

            $user->update([
                'statut' => 'actif'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur activé avec succès.',
                'data' => [
                    'id' => $user->id,
                    'statut' => $user->statut
                ]
            ]);
        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'activation.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function desactiverUtilisateur(int $id): JsonResponse
    {
        try {

            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non trouvé.'
                ], 404);
            }

            $user->update([
                'statut' => 'inactif'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur désactivé avec succès.',
                'data' => [
                    'id' => $user->id,
                    'statut' => $user->statut
                ]
            ]);
        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la désactivation.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function ajouterDlgas(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'username' => 'required|string|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            // 'profil' => 'required|string',
            'entite_id' => 'required|exists:entites,id',
            'telephone' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::create([
                'nom' => $request->nom,
                'prenom' => $request->prenom,
                'username' => $request->username,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'profil' => 'consultation',
                'entite_id' => $request->entite_id,
                'telephone' => $request->telephone,
                'statut' => 'actif',
            ]);

            return response()->json([
                'success' => true,
                'data' => $user,
                'message' => 'Utilisateur créé avec succès'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function supprimerUtilisateur($id): JsonResponse
    {
        try {
            $authUser = auth('api')->user();

            if (!$authUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur introuvable.'
                ], 404);
            }

            //  Empêcher suppression de soi-même (bonne pratique)
            if ($authUser->id == $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne pouvez pas supprimer votre propre compte.'
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
                'message' => 'Utilisateur supprimé avec succès.'
            ]);
        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de l’utilisateur.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
