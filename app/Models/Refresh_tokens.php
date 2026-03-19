<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Refresh_tokens extends Model
{
     protected $fillable = ['user_id', 'token', 'expires_at'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired()
    {
        return $this->expires_at < now();
    }
}
