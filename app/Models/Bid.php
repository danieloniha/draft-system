<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One bid a participant placed on an item. Only ever written by
 * DraftBiddingService::placeBid, which enforces that a new bid is strictly
 * higher than whatever came before it — so for any item, its latest bid row
 * is always the current leader.
 */
class Bid extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function draft()
    {
        return $this->belongsTo(Draft::class);
    }

    public function interest()
    {
        return $this->belongsTo(Interest::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
