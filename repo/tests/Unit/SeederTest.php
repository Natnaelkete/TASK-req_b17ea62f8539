<?php

namespace Tests\Unit;

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

    public function test_role_config_seeder_creates_admin(): void
    {
        $this->seed(RoleConfigSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'admin@workforce.local',
            'role' => 'system_admin',
        ]);
    }

    public function test_seeder_creates_masking_rules(): void
    {
        $this->seed(RoleConfigSeeder::class);

        $this->assertGreaterThan(0, MaskingRule::count());
        $this->assertDatabaseHas('masking_rules', ['field_name' => 'contact_last_name']);
        $this->assertDatabaseHas('masking_rules', ['field_name' => 'ssn']);
    }

    public function test_seeder_creates_job_categories(): void
    {
        $this->seed(RoleConfigSeeder::class);

        $this->assertGreaterThan(0, JobCategory::count());
        $this->assertDatabaseHas('job_categories', ['slug' => 'technology']);
        $this->assertDatabaseHas('job_categories', ['slug' => 'healthcare']);
    }

    public function test_seeder_creates_feature_flags(): void
    {
        $this->seed(RoleConfigSeeder::class);

        $this->assertDatabaseHas('feature_flags', ['key' => 'offline_mode', 'enabled' => true]);
        $this->assertDatabaseHas('feature_flags', ['key' => 'pii_masking', 'enabled' => true]);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RoleConfigSeeder::class);
        $adminCount1 = User::where('role', 'system_admin')->count();

        $this->seed(RoleConfigSeeder::class);
        $adminCount2 = User::where('role', 'system_admin')->count();

        $this->assertEquals($adminCount1, $adminCount2);
    }
}
