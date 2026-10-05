<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_guest' => 'boolean',
    ];

    /**
     * Someone who joined a session from a link with just a name: no email, no password.
     * A guest is a real user row, so everything that keys off "the logged-in user" (turns,
     * policies, broadcasting) treats them like any participant. They can play but not host.
     */
    public static function createGuest(string $username): self
    {
        return static::create([
            'username' => $username,
            'email' => null,
            // Nobody knows this: a guest gets back in through their session or their invitation link.
            'password' => Str::random(48),
            'is_guest' => true,
        ]);
    }

    public function isGuest(): bool
    {
        return (bool) $this->is_guest;
    }

    public function teams() {
        return $this->hasMany(Team::class);
    }

    public function createdDrafts() {
        return $this->hasMany(Draft::class);
    }
}
