<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tickets extends Model
{

    protected $fillable = [
        'type', 'categorie', 'statut', 'urgence',
        'date_ouverture',
        'num_ticket',
        'sla_ttr',
        'sla_started_at',
        'sla_due_at',
        'dlgas_id',
        'telephone',
        'source_demande', 'prenom', 'nom', 'email', 'adresse', 'statut_solution',
        'detail', 'user_id', 'entite_id', 'element_id', 'observateur_id'
    ];


    public function utilisateur()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function observateur()
    {
        return $this->belongsTo(User::class, 'observateur_id');
    }

    public function entite()
    {
        return $this->belongsTo(Entites::class, 'entite_id');
    }

    public function element()
    {
        return $this->belongsTo(Elements::class, 'element_id');
    }

    public function discussions()
    {
        return $this->hasMany(Discussions::class, 'ticket_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notifications::class);
    }

    public function dlgas()
{
    return $this->belongsTo(User::class, 'dlgas_id');
}


}
