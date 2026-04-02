<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Entites extends Model
{
    protected $fillable = ['nom', 'entite_principale_id'];

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
        return $this->hasMany(Tickets::class);
    }
}
