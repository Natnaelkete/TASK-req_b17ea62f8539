<?php

namespace Tests\UnitTests;

use App\Models\User;
use App\Models\MaskingRule;
use App\Models\JobCategory;
use App\Models\FeatureFlag;
use Database\Seeders\RoleConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function seeder_creates_admin_user(): void
    {
        $this->seed(RoleConfigSeeder::class);
        $this->assertDatabaseHas('users', [
            'email' => 'admin@workforce.local',
            'role' => 'system_admin',
        ]);
    }

    /** @test */
    public function seeder_creates_masking_rules(): void
    {
        $this->seed(RoleConfigSeeder::class);
        $this->assertGreaterThan(0, MaskingRule::count());
        $this->assertDatabaseHas('masking_rules', ['field_name' => 'contact_last_name']);
        $this->assertDatabaseHas('masking_rules', ['field_name' => 'ssn']);
    }

    /** @test */
    public function seeder_creates_job_categories(): void
    {
        $this->seed(RoleConfigSeeder::class);
        $this->assertGreaterThan(0, JobCategory::count());
        $this->assertDatabaseHas('job_categories', ['slug' => 'technology']);
    }

    /** @test */
    public function seeder_creates_feature_flags(): void
    {
        $this->seed(RoleConfigSeeder::class);
        $this->assertDatabaseHas('feature_flags', ['key' => 'offline_mode', 'enabled' => true]);
        $this->assertDatabaseHas('feature_flags', ['key' => 'pii_masking', 'enabled' => true]);
    }

    /** @test */
    public function seeder_is_idempotent(): void
    {
        $this->seed(RoleConfigSeeder::class);
        $count1 = User::where('role', 'system_admin')->count();
        $this->seed(RoleConfigSeeder::class);
        $count2 = User::where('role', 'system_admin')->count();
        $this->assertEquals($count1, $count2);
    }
}
