<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Withdrawal extends Model
{
    protected $guarded = [];

    public function destination()
    {
        return $this->belongsTo(PayoutDestination::class, 'payout_destination_id');
    }
}
