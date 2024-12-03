<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Draft extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function creator() {
        return $this->belongsTo(User::class);
    }

    public function interests() {
        return $this->hasMany(Interest::class);
    }

    public function selections() {
        return $this->hasMany(Selection::class);
    }

    public function teams() {
        return $this->hasMany(Team::class);
    }
}
