<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A turn that passed without a selection because the participant's timer ran out.
 */
class TurnSkip extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function team() {
        return $this->belongsTo(Team::class);
    }

    public function draft() {
        return $this->belongsTo(Draft::class);
    }
}
