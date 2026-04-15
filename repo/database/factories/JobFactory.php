<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\Employer;
use Illuminate\Database\Eloquent\Factories\Factory;

class JobFactory extends Factory
{
    protected $model = Job::class;

    public function definition(): array
    {
        $title = fake()->jobTitle();
        $salaryMin = fake()->numberBetween(30000, 80000);

        return [
            'employer_id' => Employer::factory(),
            'title' => $title,
            'normalized_title' => strtolower(trim($title)),
            'description' => fake()->paragraphs(3, true),
            'salary_min' => $salaryMin,
            'salary_max' => $salaryMin + fake()->numberBetween(5000, 50000),
            'education_level' => fake()->randomElement(['high_school', 'associate', 'bachelor', 'master', 'doctorate']),
            'work_street' => fake()->streetAddress(),
            'work_city' => fake()->city(),
            'work_state' => fake()->stateAbbr(),
            'work_zip' => fake()->postcode(),
            'status' => 'draft',
        ];
    }
}
