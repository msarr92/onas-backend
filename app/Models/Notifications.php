<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notifications extends Model
{

     protected $fillable = ['user_id', 'ticket_id', 'type', 'contenu', 'lu'];



     public function utilisateur()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function ticket()
    {
        return $this->belongsTo(Tickets::class, 'ticket_id');
    }

}
