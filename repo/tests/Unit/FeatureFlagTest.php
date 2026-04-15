<?php

namespace Tests\Unit;

use App\Models\FeatureFlag;
use App\Models\MaskingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_flag_creation(): void
    {
        $flag = FeatureFlag::firstOrCreate([
            'key' => 'test_feature',
            'enabled' => true,
            'description' => 'Test feature flag',
        ]);

        $this->assertDatabaseHas('feature_flags', ['key' => 'test_feature', 'enabled' => true]);
    }

    public function test_feature_flag_toggle(): void
    {
        $flag = FeatureFlag::firstOrCreate(['key' => 'toggle_test', 'enabled' => false]);

        $this->assertFalse(FeatureFlag::isEnabled('toggle_test'));

        $flag->update(['enabled' => true]);

        $this->assertTrue(FeatureFlag::isEnabled('toggle_test'));
    }

    public function test_masking_rule_stores_visible_roles_as_json(): void
    {
        $rule = MaskingRule::firstOrCreate([
            'field_name' => 'test_field',
            'mask_type' => 'first_initial',
            'visible_roles' => ['system_admin', 'inspector'],
        ]);

        $rule->refresh();
        $this->assertIsArray($rule->visible_roles);
        $this->assertContains('system_admin', $rule->visible_roles);
        $this->assertContains('inspector', $rule->visible_roles);
    }

    public function test_masking_rule_active_filter(): void
    {
        MaskingRule::firstOrCreate(
            ['field_name' => 'test_active_field'],
            ['mask_type' => 'redact', 'visible_roles' => ['system_admin'], 'active' => true]
        );
        MaskingRule::firstOrCreate(
            ['field_name' => 'test_inactive_field'],
            ['mask_type' => 'redact', 'visible_roles' => ['system_admin'], 'active' => false]
        );

        $this->assertDatabaseHas('masking_rules', ['field_name' => 'test_active_field', 'active' => true]);
        $this->assertDatabaseHas('masking_rules', ['field_name' => 'test_inactive_field', 'active' => false]);

        $inactive = MaskingRule::where('field_name', 'test_inactive_field')->first();
        $this->assertFalse($inactive->active);
    }
}
