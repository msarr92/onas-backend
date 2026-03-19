<?php

namespace App\Http\Controllers;

use App\Models\Elements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ElementController extends Controller
{
    public function ajouterElement(Request $request): JsonResponse
    {
        try {

            $validator = Validator::make($request->all(), [
                'nom' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('elements')->where(function ($query) use ($request) {
                        return $query->where('element_principal_id', $request->element_principal_id);
                    })
                ],
                'element_principal_id' => [
                    'nullable',
                    'exists:elements,id'
                ]
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // empêcher boucle (sécurité future update)
            if ($this->boucleElement($request->element_principal_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hiérarchie invalide : boucle détectée.'
                ], 422);
            }

            $element = Elements::create([
                'nom' => $request->nom,
                'element_principal_id' => $request->element_principal_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Élément ajouté avec succès.',
                'data' => $element->fresh('parent')
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'ajout de l\'élément.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function boucleElement(?int $parentId, ?int $elementId = null): bool
    {
        if (!$parentId) return false;

        while ($parentId) {

            if ($parentId == $elementId) {
                return true;
            }

            $parent = Elements::find($parentId);
            if (!$parent) break;

            $parentId = $parent->element_principal_id;
        }

        return false;
    }

    public function listerElement(): JsonResponse
    {
        try {

            $elements = Elements::with(['parent', 'enfants'])
                ->orderBy('nom')
                ->get();

            // 🌳 arbre
            $arbre = $this->buildElementHierarchy($elements);

            // 📄 liste plate
            $listePlate = $elements->map(function ($element) {
                return [
                    'id' => $element->id,
                    'nom' => $element->nom,
                    'element_principal_id' => $element->element_principal_id,
                    'parent_nom' => $element->parent?->nom,
                    'niveau' => $this->calculerNiveauElement($element),
                    'chemin_complet' => $this->cheminCompletElement($element),
                    'has_enfants' => $element->enfants->count() > 0,
                    'created_at' => $element->created_at,
                    'updated_at' => $element->updated_at
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
                'message' => 'Erreur lors de la récupération des éléments.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    private function buildElementHierarchy($elements, $parentId = null)
    {
        $branche = [];

        foreach ($elements->where('element_principal_id', $parentId) as $element) {

            $enfants = $this->buildElementHierarchy($elements, $element->id);

            $branche[] = [
                'id' => $element->id,
                'nom' => $element->nom,
                'element_principal_id' => $element->element_principal_id,
                'parent_nom' => $element->parent?->nom,
                'niveau' => $this->calculerNiveauElement($element),
                'has_enfants' => count($enfants) > 0,
                'enfants' => $enfants
            ];
        }

        return $branche;
    }

    private function calculerNiveauElement($element): int
    {
        $niveau = 0;
        $parentId = $element->element_principal_id;

        while ($parentId) {
            $niveau++;
            $parent = Elements::find($parentId);
            if (!$parent) break;

            $parentId = $parent->element_principal_id;
        }

        return $niveau;
    }

    private function cheminCompletElement($element): string
    {
        $chemin = [$element->nom];
        $parentId = $element->element_principal_id;

        while ($parentId) {
            $parent = Elements::find($parentId);
            if (!$parent) break;

            array_unshift($chemin, $parent->nom);
            $parentId = $parent->element_principal_id;
        }

        return implode(' > ', $chemin);
    }



    public function suppElement(int $id): JsonResponse
    {
        try {
            // Récupérer l'élément avec relations enfants et tickets
            $element = Elements::with(['enfants', 'tickets'])->find($id);

            if (!$element) {
                return response()->json([
                    'success' => false,
                    'message' => 'Élément non trouvé.'
                ], 404);
            }

            // Supprimer récursivement
            $this->supprimerRecursivementElement($element);

            return response()->json([
                'success' => true,
                'message' => "L'élément '{$element->nom}' et tous ses sous-éléments ont été supprimés avec succès.",
                'data' => [
                    'id' => $element->id,
                    'nom' => $element->nom
                ]
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de l\'élément.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function supprimerRecursivementElement($element)
    {
        // Supprimer d'abord les enfants
        foreach ($element->enfants as $enfant) {
            $this->supprimerRecursivementElement($enfant);
        }

        // Supprimer les tickets liés
        foreach ($element->tickets as $ticket) {
            $ticket->delete();
        }

        // Supprimer l'élément
        $element->delete();
    }

    public function modifierElement(Request $request, int $id): JsonResponse
    {
        try {
            $element = Elements::find($id);

            if (!$element) {
                return response()->json([
                    'success' => false,
                    'message' => 'Élément non trouvé.'
                ], 404);
            }

            // Validation
            $request->validate([
                'nom' => 'required|string|max:255',
                'element_principal_id' => 'nullable|exists:elements,id'
            ]);

            // Empêcher qu'un élément devienne son propre parent
            if ($request->element_principal_id == $id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Un élément ne peut pas être son propre parent.'
                ], 422);
            }

            // Mise à jour
            $element->update([
                'nom' => $request->nom,
                'element_principal_id' => $request->element_principal_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Élément modifié avec succès.',
                'data' => $element->fresh()
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification de l\'élément.',
                'error' => $e->getMessage()
            ], 500);
        }
    }












}
