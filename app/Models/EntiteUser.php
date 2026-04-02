<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class EntiteUser extends Pivot
{
    // Si tu veux préciser le nom de la table, sinon Laravel le détecte automatiquement
    // protected $table = 'entite_user';

    // Champs remplissables
    protected $fillable = [
        'user_id',
        'entite_id',
    ];

    // Si tu veux activer les timestamps
    public $timestamps = true;

    // Relations optionnelles
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function entite()
    {
        return $this->belongsTo(Entites::class);
    }
}
