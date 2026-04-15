<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'category_id' => 'nullable|exists:job_categories,id',
            'salary_min' => 'sometimes|integer|min:0',
            'salary_max' => 'sometimes|integer|gte:salary_min',
            'education_level' => 'sometimes|string|in:high_school,associate,bachelor,master,doctorate',
            'work_street' => 'nullable|string|max:500',
            'work_city' => 'sometimes|string|max:255',
            'work_state' => 'sometimes|string|size:2',
            'work_zip' => 'sometimes|string|max:10',
            'status' => 'sometimes|string|in:draft,active,closed,archived',
        ];
    }
}
