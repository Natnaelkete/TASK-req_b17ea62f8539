<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled by middleware
    }

    public function rules(): array
    {
        return [
            'company_name' => 'required|string|max:255',
            'trade_name' => 'nullable|string|max:255',
            'ein' => 'nullable|string|max:20',
            'contact_first_name' => 'required|string|max:255',
            'contact_last_name' => 'required|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'contact_email' => 'required|string|email|max:255',
            'street' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|size:2',
            'zip' => 'nullable|string|max:10',
            'qualifications' => 'nullable|array',
            'qualifications.*.qualification_type' => 'required_with:qualifications|string|max:255',
            'qualifications.*.license_number' => 'nullable|string|max:255',
            'qualifications.*.issued_at' => 'nullable|date',
            'qualifications.*.expires_at' => 'nullable|date|after_or_equal:qualifications.*.issued_at',
            'documents' => 'nullable|array',
            'documents.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png',
        ];
    }
}
