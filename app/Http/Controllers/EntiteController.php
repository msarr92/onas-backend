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
            // Récupérer toutes les entités avec leurs relations
            $entites = Entites::with(['parent', 'enfants'])
                ->orderBy('nom')
                ->get();

            // Construire l'arbre hiérarchique
            $arbre = $this->buildHierarchy($entites);

            // Version plate avec chemin complet
            $listePlate = $entites->map(function ($entite) {
                return [
                    'id' => $entite->id,
                    'nom' => $entite->nom,
                    'entite_principale_id' => $entite->entite_principale_id,
                    'entite_principale_nom' => $entite->entitePrincipale?->nom,
                    'niveau' => $entite->niveau,
                    'chemin_complet' => $entite->chemin_hierarchique,
                    'has_enfants' => $entite->enfants->count() > 0,
                    'created_at' => $entite->created_at,
                    'updated_at' => $entite->updated_at
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'liste' => $listePlate,
                    'arbre' => $arbre
                ]
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des entités.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function showHierarchy($id): JsonResponse
    {
        try {

            $entite = Entites::with([
                'parent',
                'enfants.enfants', // 🔁 charge 2 niveaux (extensible)
                'utilisateurs:id,nom,prenom,entite_id',
                'tickets:id,type,statut,entite_id'
            ])->find($id);

            if (!$entite) {
                return response()->json([
                    'success' => false,
                    'message' => 'Entité non trouvée.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Hiérarchie entité récupérée avec succès.',
                'data' => $entite
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement de la hiérarchie.',
                'error' => $e->getMessage()
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
                    })
                ],
                'entite_principale_id' => [
                    'nullable',
                    'exists:entites,id'
                ]
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // 🔒 Vérifier limite niveau
            if ($request->entite_principale_id) {

                $niveauParent = $this->calculerNiveauParId($request->entite_principale_id);
                $niveauEnfant = $niveauParent + 1;

                if ($niveauEnfant > 4) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Impossible de créer cette entité : profondeur maximale (niveau 2) atteinte.'
                    ], 422);
                }
            }

            // Création
            $entite = Entites::create([
                'nom' => $request->nom,
                'entite_principale_id' => $request->entite_principale_id
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
                    'updated_at' => $entite->updated_at
                ]
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'entité.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    private function creeraitUneBoucle(?int $parentId, ?int $enfantId = null): bool
    {
        if (!$parentId) return false;

        while ($parentId) {

            if ($parentId == $enfantId) {
                return true; // boucle détectée
            }

            $parent = Entites::find($parentId);
            if (!$parent) break;

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
            if (!$parent) break;

            $parentId = $parent->entite_principale_id;
        }

        return $niveau;
    }



    /**
     * Calculer le niveau hiérarchique
     */
    private function calculerNiveau($entite): int
    {
        $niveau = 0;
        $parentId = $entite->entite_principale_id;

        while ($parentId) {
            $niveau++;
            $parent = Entites::find($parentId);
            $parentId = $parent ? $parent->entite_principale_id : null;
        }

        return $niveau;
    }


      /**
     * Déterminer le type d'entité selon le niveau
     */
    private function determinerTypeEntite($entite): string
    {
        $niveau = $entite->niveau;

        switch ($niveau) {
            case 0:
                return 'ORGANISATION_PRINCIPALE';
            case 1:
                return 'RESEAU';
            // case 2:
            //     return 'VILLE';
            default:
                return 'VILLE';
        }
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
                    'enfants' => $enfants
                ];
            }
        }

        return $branche;
    }



    public function supprimerEntiteForce(int $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            //, 'utilisateurs', 'tickets'
            $entite = Entites::with(['enfants'])->find($id);

            if (!$entite) {
                return response()->json([
                    'success' => false,
                    'message' => 'Entité non trouvée.'
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
                    'nom' => $nomEntite
                ]
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression forcée.',
                'error' => $e->getMessage()
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

     /**
     * Supprimer récursivement une entité et ses enfants
     */
    private function supprimerRecursivement($entite): void
    {
        // Supprimer d'abord tous les enfants
        foreach ($entite->enfants as $enfant) {
            $this->supprimerRecursivement($enfant);
        }

        // Supprimer les tickets associés (soft delete ou hard delete selon votre besoin)
        if ($entite->tickets) {
            foreach ($entite->tickets as $ticket) {
                $ticket->delete(); // ou $ticket->forceDelete() pour suppression définitive
            }
        }

        // Supprimer l'entité elle-même
        $entite->delete();
    }

    public function modifierEntite(Request $request, int $id): JsonResponse
    {
        try {

            $entite = Entites::find($id);
            if (!$entite) {
                return response()->json([
                    'success' => false,
                    'message' => 'Entité non trouvée.'
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
                        })
                ],
                'entite_principale_id' => [
                    'nullable',
                    'exists:entites,id'
                ]
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // ❌ lui-même parent
            if ($request->entite_principale_id == $id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une entité ne peut pas être son propre parent.'
                ], 422);
            }

            // ❌ boucle hiérarchique
            if ($this->creeraitUneBoucle($request->entite_principale_id, $id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Modification impossible : cela créerait une boucle hiérarchique.'
                ], 422);
            }

            $entite->update([
                'nom' => $request->nom,
                'entite_principale_id' => $request->entite_principale_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Entité modifiée avec succès.',
                'data' => $entite->fresh('parent')
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification.',
                'error' => $e->getMessage()
            ], 500);
        }
    }



}
