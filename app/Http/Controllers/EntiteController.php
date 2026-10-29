<?php

namespace App\Http\Controllers;

use App\Models\Entites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class EntiteController extends Controller
{
    /**
     * LISTER TOUTES LES ENTITÉS AVEC HIÉRARCHIE
     * GET /api/entites
     */
    public function ListEntites(): JsonResponse
    {
        try {
            $entites = Entites::with(['parent', 'enfants'])
                ->orderBy('nom')
                ->get();

            $arbre = $this->buildHierarchy($entites);

            // ✅ Liste plate avec entite_principale_id (CRITIQUE pour le front)
            $listePlate = $entites->map(function ($entite) {
                // Calculer le niveau
                $niveau = 0;
                $parentId = $entite->entite_principale_id;
                while ($parentId) {
                    $niveau++;
                    $parent = Entites::find($parentId);
                    if (! $parent) {
                        break;
                    }
                    $parentId = $parent->entite_principale_id;
                }

                return [
                    'id' => (int) $entite->id,
                    'nom' => $entite->nom,
                    'entite_principale_id' => $entite->entite_principale_id !== null
                        ? (int) $entite->entite_principale_id
                        : null,
                    'entite_principale_nom' => $entite->parent?->nom,
                    'niveau' => $niveau,
                    'chemin_complet' => $entite->chemin_hierarchique ?? $entite->nom,
                    'has_enfants' => $entite->enfants->count() > 0,
                    'created_at' => $entite->created_at,
                    'updated_at' => $entite->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'liste' => $listePlate,
                    'arbre' => $arbre,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des entités.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function showHierarchy($id): JsonResponse
{
    try {
        $entite = Entites::find($id);

        if (! $entite) {
            return response()->json([
                'success' => false,
                'message' => 'Entité non trouvée.',
            ], 404);
        }

        // ✅ Charger les relations UNE PAR UNE pour identifier le problème
        $relations = ['parent', 'enfants.enfants'];

        // Ajouter 'utilisateurs' SEULEMENT si la relation existe
        if (method_exists(Entites::class, 'utilisateurs')) {
            // Utiliser la clé étrangère correcte
            $userForeignKey = \Schema::hasColumn('users', 'entite_id') 
                ? 'entite_id' 
                : null;
            
            if ($userForeignKey) {
                $relations[] = 'utilisateurs:id,nom,prenom,' . $userForeignKey;
            } else {
                // Many-to-many : ne pas charger la relation directe
                $relations[] = 'utilisateurs:id,nom,prenom';
            }
        }

        // Ajouter 'tickets' SEULEMENT si la relation existe
        if (method_exists(Entites::class, 'tickets')) {
            if (\Schema::hasColumn('tickets', 'entite_id')) {
                $relations[] = 'tickets:id,type,statut,entite_id';
            }
        }

        $entite->load($relations);

        return response()->json([
            'success' => true,
            'message' => 'Hiérarchie entité récupérée avec succès.',
            'data' => $entite,
        ]);

    } catch (\Throwable $e) {
        // ✅ Retourner l'erreur exacte pour diagnostic
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors du chargement de la hiérarchie.',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], 500);
    }
}

    /**
     * Ajouter une entité
     */
    public function ajoutEntite(Request $request): JsonResponse
    {
        try {

            $validator = Validator::make($request->all(), [
                'nom' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('entites')->where(function ($query) use ($request) {
                        return $query->where('entite_principale_id', $request->entite_principale_id);
                    }),
                ],
                'entite_principale_id' => [
                    'nullable',
                    'exists:entites,id',
                ],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            // 🔒 Vérifier limite niveau
            if ($request->entite_principale_id) {

                $niveauParent = $this->calculerNiveauParId($request->entite_principale_id);
                $niveauEnfant = $niveauParent + 1;

                if ($niveauEnfant > 4) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Impossible de créer cette entité : profondeur maximale (niveau 2) atteinte.',
                    ], 422);
                }
            }

            // Création
            $entite = Entites::create([
                'nom' => $request->nom,
                'entite_principale_id' => $request->entite_principale_id,
            ]);

            $entite = Entites::with('parent')->find($entite->id);

            $niveau = $this->calculerNiveauParId($entite->entite_principale_id);
            $parentNom = $entite->parent ? $entite->parent->nom : null;

            return response()->json([
                'success' => true,
                'message' => 'Entité créée avec succès.',
                'data' => [
                    'id' => $entite->id,
                    'nom' => $entite->nom,
                    'entite_principale_id' => $entite->entite_principale_id,
                    'entite_principale_nom' => $parentNom,
                    'niveau' => $niveau,
                    'created_at' => $entite->created_at,
                    'updated_at' => $entite->updated_at,
                ],
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'entité.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function creeraitUneBoucle(?int $parentId, ?int $enfantId = null): bool
    {
        if (! $parentId) {
            return false;
        }

        while ($parentId) {

            if ($parentId == $enfantId) {
                return true; // boucle détectée
            }

            $parent = Entites::find($parentId);
            if (! $parent) {
                break;
            }

            $parentId = $parent->entite_principale_id;
        }

        return false;
    }

    private function calculerNiveauParId(?int $parentId): int
    {
        $niveau = 0;

        while ($parentId) {
            $niveau++;
            $parent = Entites::find($parentId);

            if (! $parent) {
                break;
            }

            $parentId = $parent->entite_principale_id;
        }

        return $niveau;
    }

    /**
     * Construire l'arbre hiérarchique
     */
    private function buildHierarchy($entites, $parentId = null)
    {
        $branche = [];

        foreach ($entites as $entite) {
            if ($entite->entite_principale_id == $parentId) {
                $enfants = $this->buildHierarchy($entites, $entite->id);

                $branche[] = [
                    'id' => $entite->id,
                    'nom' => $entite->nom,
                    'niveau' => $entite->niveau,
                    'enfants' => $enfants,
                ];
            }
        }

        return $branche;
    }

    public function supprimerEntiteForce(int $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            // , 'utilisateurs', 'tickets'
            $entite = Entites::with(['enfants'])->find($id);

            if (! $entite) {
                return response()->json([
                    'success' => false,
                    'message' => 'Entité non trouvée.',
                ], 404);
            }
            $nomEntite = $entite->nom;

            // 3️⃣ Supprimer récursivement les sous-entités
            $this->deleteChildren($entite);

            // 4️⃣ Supprimer l'entité
            $entite->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "L'entité '$nomEntite' a été supprimée définitivement.",
                'data' => [
                    'id' => $id,
                    'nom' => $nomEntite,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression forcée.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function deleteChildren($entite)
    {
        foreach ($entite->enfants as $enfant) {

            // appel récursif
            $this->deleteChildren($enfant);

            // supprimer enfant
            $enfant->delete();
        }
    }

    public function modifierEntite(Request $request, int $id): JsonResponse
    {
        try {

            $entite = Entites::find($id);
            if (! $entite) {
                return response()->json([
                    'success' => false,
                    'message' => 'Entité non trouvée.',
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'nom' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('entites')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('entite_principale_id', $request->entite_principale_id);
                        }),
                ],
                'entite_principale_id' => [
                    'nullable',
                    'exists:entites,id',
                ],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            // ❌ lui-même parent
            if ($request->entite_principale_id == $id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une entité ne peut pas être son propre parent.',
                ], 422);
            }

            // ❌ boucle hiérarchique
            if ($this->creeraitUneBoucle($request->entite_principale_id, $id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Modification impossible : cela créerait une boucle hiérarchique.',
                ], 422);
            }

            $entite->update([
                'nom' => $request->nom,
                'entite_principale_id' => $request->entite_principale_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Entité modifiée avec succès.',
                'data' => $entite->fresh('parent'),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function getSousEntites($entiteId)
    {
        $sousEntites = [];

        $enfants = Entites::where('entite_principale_id', $entiteId)->get();

        foreach ($enfants as $enfant) {
            $sousEntites[] = $enfant;

            $sousEntites = array_merge(
                $sousEntites,
                $this->getSousEntites($enfant->id)
            );
        }

        return $sousEntites;
    }

    public function mesSousEntites(): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié',
                ], 401);
            }

            $entiteUser = Entites::find($user->entite_id);

            if (! $entiteUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Entité utilisateur introuvable',
                ], 404);
            }

            $sousEntites = $this->getSousEntites($entiteUser->id);

            return response()->json([
                'success' => true,
                'entite_utilisateur' => [
                    'id' => (int) $entiteUser->id,
                    'nom' => $entiteUser->nom,
                    'entite_principale_id' => $entiteUser->entite_principale_id,
                    'niveau' => $entiteUser->niveau ?? 0,
                    'chemin_complet' => $entiteUser->chemin_hierarchique ?? $entiteUser->nom,
                ],
                'nombre_sous_entites' => count($sousEntites),
                'sous_entites' => $sousEntites,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des sous-entités',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function sousEntites($id)
    {
        try {
            $entite = Entites::find($id);

            if (! $entite) {
                return response()->json([
                    'success' => false,
                    'message' => 'Entité principale introuvable.',
                ], 404);
            }

            $sousEntites = Entites::where('entite_principale_id', $id)
                ->orderBy('nom', 'asc')
                ->get()
                ->map(function ($se) {
                    $niveau = 1;

                    return [
                        'id' => (int) $se->id,
                        'nom' => $se->nom,
                        'entite_principale_id' => (int) $se->entite_principale_id,
                        'niveau' => $niveau,
                        'chemin_complet' => $se->chemin_hierarchique ?? $se->nom,
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Sous-entités récupérées avec succès.',
                'entite_principale' => [
                    'id' => (int) $entite->id,
                    'nom' => $entite->nom,
                    'niveau' => $entite->niveau ?? 0,
                ],
                'sous_entites' => $sousEntites,
                'nombre_sous_entites' => $sousEntites->count(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des sous-entités.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
