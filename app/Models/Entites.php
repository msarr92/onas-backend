<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Entites extends Model
{
    protected $fillable = ['nom', 'entite_principale_id'];

    protected $appends = ['niveau', 'chemin_hierarchique'];

    public function parent()
    {
        return $this->belongsTo(Entites::class, 'entite_principale_id');
    }

    public function enfants()
    {
        return $this->hasMany(Entites::class, 'entite_principale_id');
    }

    public function utilisateurs()
    {
        return $this->belongsToMany(User::class, 'entite_user', 'entite_id', 'user_id')->withTimestamps();
    }

    public function tickets()
    {
        return $this->hasMany(Tickets::class, 'entite_id');
    }


    /**
     * Calcule le niveau hiérarchique de l'entité
     * Niveau 0 = racine (aucun parent)
     * Niveau 1 = enfant d'une racine
     * Niveau 2 = petit-enfant
     * etc.
     *
     * @return int
     */
    public function getNiveauAttribute(): int
    {
        $niveau = 0;
        $parentId = $this->entite_principale_id;

        while ($parentId) {
            $niveau++;

            $parent = self::find($parentId);

            if (! $parent) {
                break;
            }

            $parentId = $parent->entite_principale_id;

            // Sécurité anti-boucle
            if ($niveau > 20) {
                break;
            }
        }

        return $niveau;
    }

    /**
     * Chemin hiérarchique complet : "ONAS → Dakar → Corniche"
     *
     * @return string
     */
    public function getCheminHierarchiqueAttribute(): string
    {
        $path = [$this->nom];
        $parentId = $this->entite_principale_id;
        $securite = 0;

        while ($parentId && $securite < 20) {
            $securite++;

            $parent = self::find($parentId);

            if (! $parent) {
                break;
            }

            array_unshift($path, $parent->nom);
            $parentId = $parent->entite_principale_id;
        }

        return implode(' → ', $path);
    }

}
