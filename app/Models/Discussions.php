<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Discussions extends Model
{

        protected $fillable = [
            'ticket_id',
            'user_id',
            'message',
            'type',
            'accepted_at',
            'accepted_by',
            'rejected_at',
            'rejected_by',
            'rejection_reason'
        ];


    protected $casts = [
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relation avec l'utilisateur qui a accepté
    public function acceptedBy()
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    // Relation avec l'utilisateur qui a refusé
    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

     public function ticket()
    {
        return $this->belongsTo(tickets::class, 'ticket_id');
    }

    public function utilisateur()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

}
