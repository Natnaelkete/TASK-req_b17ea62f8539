<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category_id' => 'nullable|exists:job_categories,id',
            'salary_min' => 'required|integer|min:0',
            'salary_max' => 'required|integer|gte:salary_min',
            'education_level' => 'required|string|in:high_school,associate,bachelor,master,doctorate',
            'work_street' => 'nullable|string|max:500',
            'work_city' => 'required|string|max:255',
            'work_state' => 'required|string|size:2',
            'work_zip' => 'required|string|max:10',
        ];
    }
}
