<?php

namespace Database\Factories;

use App\Models\ResultVersion;
use App\Models\Job;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResultVersionFactory extends Factory
{
    protected $model = ResultVersion::class;

    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'version_number' => 1,
            'status' => 'draft',
            'data' => ['summary' => fake()->sentence(), 'score' => fake()->numberBetween(1, 100)],
            'created_by' => User::factory(),
        ];
    }
}
