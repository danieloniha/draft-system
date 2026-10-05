<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Interest extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function draft() {
        return $this->belongsTo(Draft::class);
    }

    public function bids() {
        return $this->hasMany(Bid::class);
    }

    public function winningTeam() {
        return $this->belongsTo(Team::class, 'winning_team_id');
    }
}
