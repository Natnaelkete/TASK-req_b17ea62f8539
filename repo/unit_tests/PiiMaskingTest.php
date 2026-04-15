<?php

namespace Tests\UnitTests;

use App\Models\Employer;
use App\Models\Job;
use App\Models\MaskingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PiiMaskingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MaskingRule::firstOrCreate(['field_name' => 'contact_last_name'], ['mask_type' => 'first_initial', 'visible_roles' => ['system_admin', 'compliance_reviewer']]);
        MaskingRule::firstOrCreate(['field_name' => 'contact_phone'], ['mask_type' => 'last_four', 'visible_roles' => ['system_admin']]);
        MaskingRule::firstOrCreate(['field_name' => 'contact_email'], ['mask_type' => 'partial_email', 'visible_roles' => ['system_admin']]);
        MaskingRule::firstOrCreate(['field_name' => 'street'], ['mask_type' => 'redact', 'visible_roles' => ['system_admin']]);
        MaskingRule::firstOrCreate(['field_name' => 'ein'], ['mask_type' => 'last_four', 'visible_roles' => ['system_admin']]);
        MaskingRule::firstOrCreate(['field_name' => 'work_street'], ['mask_type' => 'redact', 'visible_roles' => ['system_admin', 'inspector']]);
    }

    /** @test */
    public function first_initial_mask_returns_initial_dot(): void
    {
        $employer = Employer::factory()->create(['contact_last_name' => 'Anderson']);
        $employer->refresh();
        $masked = $employer->maskForRole('general_user');
        $this->assertEquals('A.', $masked['contact_last_name']);
    }

    /** @test */
    public function last_four_mask_preserves_last_four_chars(): void
    {
        $employer = Employer::factory()->create(['contact_phone' => '555-123-4567']);
        $employer->refresh();
        $masked = $employer->maskForRole('general_user');
        $this->assertStringEndsWith('4567', $masked['contact_phone']);
        $this->assertStringStartsWith('*', $masked['contact_phone']);
    }

    /** @test */
    public function partial_email_mask_preserves_first_char_and_domain(): void
    {
        $employer = Employer::factory()->create(['contact_email' => 'john.doe@example.com']);
        $employer->refresh();
        $masked = $employer->maskForRole('general_user');
        $this->assertStringStartsWith('j', $masked['contact_email']);
        $this->assertStringContainsString('@example.com', $masked['contact_email']);
    }

    /** @test */
    public function redact_mask_returns_redacted_string(): void
    {
        $employer = Employer::factory()->create(['street' => '456 Oak Ave']);
        $employer->refresh();
        $masked = $employer->maskForRole('general_user');
        $this->assertEquals('***REDACTED***', $masked['street']);
    }

    /** @test */
    public function admin_sees_all_fields_unmasked(): void
    {
        $employer = Employer::factory()->create([
            'contact_last_name' => 'Roberts',
            'contact_phone' => '555-111-2222',
            'contact_email' => 'mary@example.com',
            'street' => '789 Pine Blvd',
            'ein' => '98-7654321',
        ]);
        $employer->refresh();
        $masked = $employer->maskForRole('system_admin');

        $this->assertEquals('Roberts', $masked['contact_last_name']);
        $this->assertEquals('555-111-2222', $masked['contact_phone']);
        $this->assertEquals('mary@example.com', $masked['contact_email']);
        $this->assertEquals('789 Pine Blvd', $masked['street']);
        $this->assertEquals('98-7654321', $masked['ein']);
    }

    /** @test */
    public function compliance_reviewer_has_partial_visibility(): void
    {
        $employer = Employer::factory()->create([
            'contact_last_name' => 'Taylor',
            'contact_phone' => '555-333-4444',
        ]);
        $employer->refresh();
        $masked = $employer->maskForRole('compliance_reviewer');

        $this->assertEquals('Taylor', $masked['contact_last_name']);
        $this->assertNotEquals('555-333-4444', $masked['contact_phone']);
    }

    /** @test */
    public function null_role_masks_everything(): void
    {
        $employer = Employer::factory()->create(['contact_last_name' => 'Davis']);
        $employer->refresh();
        $masked = $employer->maskForRole(null);
        $this->assertEquals('D.', $masked['contact_last_name']);
    }

    /** @test */
    public function null_pii_fields_stay_null_in_masked_output(): void
    {
        $employer = Employer::factory()->create([
            'contact_phone' => null,
            'street' => null,
        ]);
        $employer->refresh();
        $masked = $employer->maskForRole('general_user');
        $this->assertNull($masked['contact_phone']);
        $this->assertNull($masked['street']);
    }

    /** @test */
    public function invalid_email_masked_safely(): void
    {
        $employer = Employer::factory()->create(['contact_email' => 'notanemail']);
        $employer->refresh();
        $masked = $employer->maskForRole('general_user');
        $this->assertEquals('***@***.***', $masked['contact_email']);
    }

    /** @test */
    public function job_work_street_masked_for_general_user(): void
    {
        $job = Job::factory()->create(['work_street' => '100 Test Lane']);
        $job->refresh();
        $masked = $job->maskForRole('general_user');
        $this->assertEquals('***REDACTED***', $masked['work_street']);
    }

    /** @test */
    public function job_work_street_visible_to_inspector(): void
    {
        $job = Job::factory()->create(['work_street' => '100 Test Lane']);
        $job->refresh();
        $masked = $job->maskForRole('inspector');
        $this->assertEquals('100 Test Lane', $masked['work_street']);
    }
}
