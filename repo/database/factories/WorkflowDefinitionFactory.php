<?php

namespace Database\Factories;

use App\Models\WorkflowDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowDefinitionFactory extends Factory
{
    protected $model = WorkflowDefinition::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'slug' => fake()->unique()->slug(),
            'version' => 1,
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'next' => 'review'],
                ['id' => 'review', 'type' => 'approval', 'next_approve' => 'end', 'next_reject' => 'end'],
                ['id' => 'end', 'type' => 'end'],
            ],
            'approval_mode' => 'all_approve',
            'timeout_hours' => 48,
            'active' => true,
        ];
    }
}
