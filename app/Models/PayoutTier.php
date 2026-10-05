<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of a Money Split draft's payout table: every participant whose rank
 * falls in [rank_from, rank_to] is paid `amount` (each, not shared).
 */
class PayoutTier extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function draft()
    {
        return $this->belongsTo(Draft::class);
    }
}
