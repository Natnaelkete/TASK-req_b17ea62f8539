<?php

namespace App\Http\Requests;

use App\Models\Employer;
use Illuminate\Foundation\Http\FormRequest;

class ReviewEmployerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['system_admin', 'compliance_reviewer']);
    }

    public function rules(): array
    {
        return [
            'action' => 'required|string|in:approve,reject',
            'reason_code' => 'required_if:action,reject|nullable|string|in:' . implode(',', array_keys(Employer::REJECTION_REASONS)),
            'notes' => 'nullable|string|max:1000',
        ];
    }
}
