<?php

namespace Tests\Unit;

use App\Models\Employer;
use App\Models\Job;
use App\Models\MaskingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaskingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up masking rules for all PII fields (firstOrCreate for idempotency)
        MaskingRule::firstOrCreate(['field_name' => 'contact_last_name'], ['mask_type' => 'first_initial', 'visible_roles' => ['system_admin', 'compliance_reviewer']]);
        MaskingRule::firstOrCreate(['field_name' => 'contact_phone'], ['mask_type' => 'last_four', 'visible_roles' => ['system_admin']]);
        MaskingRule::firstOrCreate(['field_name' => 'contact_email'], ['mask_type' => 'partial_email', 'visible_roles' => ['system_admin']]);
        MaskingRule::firstOrCreate(['field_name' => 'street'], ['mask_type' => 'redact', 'visible_roles' => ['system_admin']]);
        MaskingRule::firstOrCreate(['field_name' => 'ein'], ['mask_type' => 'last_four', 'visible_roles' => ['system_admin']]);
        MaskingRule::firstOrCreate(['field_name' => 'work_street'], ['mask_type' => 'redact', 'visible_roles' => ['system_admin', 'inspector']]);
    }

    public function test_first_initial_mask(): void
    {
        $employer = Employer::factory()->create(['contact_last_name' => 'Anderson']);
        $employer->refresh();
        $masked = $employer->maskForRole('general_user');
        $this->assertEquals('A.', $masked['contact_last_name']);
    }

    public function test_last_four_mask(): void
    {
        $employer = Employer::factory()->create(['contact_phone' => '555-123-4567']);
        $employer->refresh();
        $masked = $employer->maskForRole('general_user');
        $this->assertStringEndsWith('4567', $masked['contact_phone']);
        $this->assertStringStartsWith('*', $masked['contact_phone']);
    }

    public function test_partial_email_mask(): void
    {
        $employer = Employer::factory()->create(['contact_email' => 'john.doe@example.com']);
        $employer->refresh();
        $masked = $employer->maskForRole('general_user');
        $this->assertStringStartsWith('j', $masked['contact_email']);
        $this->assertStringContainsString('@example.com', $masked['contact_email']);
    }

    public function test_redact_mask(): void
    {
        $employer = Employer::factory()->create(['street' => '456 Oak Ave']);
        $employer->refresh();
        $masked = $employer->maskForRole('general_user');
        $this->assertEquals('***REDACTED***', $masked['street']);
    }

    public function test_admin_sees_all_unmasked(): void
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

    public function test_compliance_reviewer_partial_access(): void
    {
        $employer = Employer::factory()->create([
            'contact_last_name' => 'Taylor',
            'contact_phone' => '555-333-4444',
        ]);
        $employer->refresh();
        $masked = $employer->maskForRole('compliance_reviewer');

        // compliance_reviewer can see contact_last_name
        $this->assertEquals('Taylor', $masked['contact_last_name']);
        // But not contact_phone (only system_admin)
        $this->assertNotEquals('555-333-4444', $masked['contact_phone']);
    }

    public function test_job_pii_masking(): void
    {
        $job = Job::factory()->create(['work_street' => '100 Test Lane']);
        $job->refresh();
        $masked = $job->maskForRole('general_user');
        $this->assertEquals('***REDACTED***', $masked['work_street']);
    }

    public function test_job_pii_visible_to_inspector(): void
    {
        $job = Job::factory()->create(['work_street' => '100 Test Lane']);
        $job->refresh();
        $masked = $job->maskForRole('inspector');
        $this->assertEquals('100 Test Lane', $masked['work_street']);
    }

    public function test_mask_email_with_invalid_format(): void
    {
        $employer = Employer::factory()->create(['contact_email' => 'notanemail']);
        $employer->refresh();
        $masked = $employer->maskForRole('general_user');
        // Invalid email gets masked differently
        $this->assertEquals('***@***.***', $masked['contact_email']);
    }

    public function test_null_pii_fields_return_null_in_mask(): void
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
}
