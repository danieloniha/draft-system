<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FundWalletRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:100', 'max:10000000'],
        ];
    }
}
