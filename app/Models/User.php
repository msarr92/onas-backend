<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'nom',
        'prenom',
        'username',
        'email',
        'profil',
        'password',
        'derniere_connexion',
        'entite_id',
        'statut',
        'telephone'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }


    public function entites()
    {
        return $this->belongsToMany(Entites::class, 'entite_user', 'user_id', 'entite_id')->withTimestamps();
    }

    public function tickets()
    {
        return $this->hasMany(Tickets::class);
    }

    public function discussions()
    {
        return $this->hasMany(Discussions::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notifications::class);
    }

    /**
     * Relation avec le DLGAS (utilisateur assigné)
     */
    public function dlgas()
    {
        return $this->belongsTo(User::class, 'dlgas', 'id');
    }

     // Relation one-to-many (si vous gardez entite_id)
    public function entite()
    {
        return $this->belongsTo(Entites::class, 'entite_id');
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }
}
