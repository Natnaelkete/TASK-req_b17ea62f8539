<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\MaskingRule;
use App\Models\JobCategory;
use App\Models\FeatureFlag;
use Illuminate\Support\Facades\Hash;

class RoleConfigSeeder extends Seeder
{
    /**
     * Seed system roles, masking rules, job categories, and feature flags.
     * Never seeds business entities (employers, jobs, inspections).
     */
    public function run(): void
    {
        // Create default system admin only if none exists
        if (!User::where('role', 'system_admin')->exists()) {
            User::create([
                'username' => 'system_admin',
                'first_name' => 'System',
                'last_name' => 'Admin',
                'email' => 'admin@workforce.local',
                'password' => Hash::make('Admin@12345678'),
                'role' => 'system_admin',
                'disabled' => false,
            ]);
        }

        // Seed masking rules (configurable, not hardcoded)
        $maskingRules = [
            ['field_name' => 'contact_last_name', 'mask_type' => 'first_initial', 'visible_roles' => ['system_admin', 'compliance_reviewer', 'inspector']],
            ['field_name' => 'contact_phone', 'mask_type' => 'last_four', 'visible_roles' => ['system_admin', 'compliance_reviewer', 'inspector']],
            ['field_name' => 'contact_email', 'mask_type' => 'partial_email', 'visible_roles' => ['system_admin', 'compliance_reviewer', 'inspector', 'employer_manager']],
            ['field_name' => 'street', 'mask_type' => 'redact', 'visible_roles' => ['system_admin', 'compliance_reviewer', 'inspector']],
            ['field_name' => 'ein', 'mask_type' => 'last_four', 'visible_roles' => ['system_admin']],
            ['field_name' => 'ssn', 'mask_type' => 'last_four', 'visible_roles' => ['system_admin']],
            ['field_name' => 'last_name', 'mask_type' => 'first_initial', 'visible_roles' => ['system_admin', 'compliance_reviewer', 'inspector']],
            ['field_name' => 'phone', 'mask_type' => 'last_four', 'visible_roles' => ['system_admin', 'compliance_reviewer', 'inspector']],
            ['field_name' => 'work_street', 'mask_type' => 'redact', 'visible_roles' => ['system_admin', 'compliance_reviewer', 'inspector']],
            ['field_name' => 'date_of_birth', 'mask_type' => 'year_only', 'visible_roles' => ['system_admin', 'compliance_reviewer']],
        ];

        foreach ($maskingRules as $rule) {
            if (!MaskingRule::where('field_name', $rule['field_name'])->exists()) {
                MaskingRule::create($rule);
            }
        }

        // Seed job categories (allowed list)
        $categories = [
            'Technology', 'Healthcare', 'Finance', 'Education',
            'Manufacturing', 'Construction', 'Retail', 'Transportation',
            'Agriculture', 'Government', 'Hospitality', 'Legal',
        ];

        foreach ($categories as $category) {
            $slug = \Illuminate\Support\Str::slug($category);
            if (!JobCategory::where('slug', $slug)->exists()) {
                JobCategory::create(['name' => $category, 'slug' => $slug, 'active' => true]);
            }
        }

        // Seed feature flags
        $flags = [
            ['key' => 'offline_mode', 'enabled' => true, 'description' => 'Enable offline inspection mode'],
            ['key' => 'pii_masking', 'enabled' => true, 'description' => 'Enable PII field masking in responses'],
            ['key' => 'rate_limiting', 'enabled' => true, 'description' => 'Enable job posting rate limits'],
            ['key' => 'duplicate_detection', 'enabled' => true, 'description' => 'Enable job duplicate detection'],
        ];

        foreach ($flags as $flag) {
            if (!FeatureFlag::where('key', $flag['key'])->exists()) {
                FeatureFlag::create($flag);
            }
        }
    }
}
