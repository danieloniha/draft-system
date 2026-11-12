<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WithdrawRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:100', 'max:'.max((int) $this->user()->balance, 100)],
            // Only the user's own saved accounts.
            'destination_id' => ['required', Rule::exists('payout_destinations', 'id')->where('user_id', $this->user()->id)],
        ];
    }
}
