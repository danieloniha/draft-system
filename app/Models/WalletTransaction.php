<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $guarded = [];

    protected $casts = ['meta' => 'array'];
}
