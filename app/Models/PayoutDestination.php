<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayoutDestination extends Model
{
    protected $guarded = [];

    public function maskedAccount(): string
    {
        return str_repeat('•', max(strlen($this->account_number) - 4, 0)).substr($this->account_number, -4);
    }
}
