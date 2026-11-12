<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePayoutDestinationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'account_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'digits:10'],
            'bank_code' => ['required', 'string', 'max:20'],
        ];
    }
}
