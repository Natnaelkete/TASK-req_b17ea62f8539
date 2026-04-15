<?php

namespace Database\Factories;

use App\Models\WorkflowInstance;
use App\Models\WorkflowDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowInstanceFactory extends Factory
{
    protected $model = WorkflowInstance::class;

    public function definition(): array
    {
        return [
            'workflow_definition_id' => WorkflowDefinition::factory(),
            'entity_type' => 'employer',
            'entity_id' => 1,
            'current_node' => 'start',
            'status' => 'pending',
            'initiated_by' => User::factory(),
        ];
    }
}
