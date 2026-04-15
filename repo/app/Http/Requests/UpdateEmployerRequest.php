<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name' => 'sometimes|string|max:255',
            'trade_name' => 'nullable|string|max:255',
            'contact_first_name' => 'sometimes|string|max:255',
            'contact_last_name' => 'sometimes|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'contact_email' => 'sometimes|string|email|max:255',
            'street' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|size:2',
            'zip' => 'nullable|string|max:10',
        ];
    }
}
