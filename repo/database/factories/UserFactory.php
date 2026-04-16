<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make('TestPass@12345'),
            'phone' => fake()->phoneNumber(),
            'role' => 'general_user',
            'disabled' => false,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'system_admin',
        ]);
    }

    public function complianceReviewer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'compliance_reviewer',
        ]);
    }

    public function employerManager(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'employer_manager',
        ]);
    }

    public function inspector(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'inspector',
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'disabled' => true,
        ]);
    }
}
