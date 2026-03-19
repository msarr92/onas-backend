<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Discussions extends Model
{

        protected $fillable = ['ticket_id', 'user_id', 'message', 'type'];

     public function ticket()
    {
        return $this->belongsTo(tickets::class, 'ticket_id');
    }

    public function utilisateur()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

}
