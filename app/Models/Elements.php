<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Elements extends Model
{

        protected $fillable = ['nom', 'element_principal_id'];

     public function parent()
    {
        return $this->belongsTo(Elements::class, 'element_principal_id');
    }

    public function enfants()
    {
        return $this->hasMany(Elements::class, 'element_principal_id');
    }

    public function tickets()
    {
        return $this->hasMany(Tickets::class, 'element_id');
    }
}
