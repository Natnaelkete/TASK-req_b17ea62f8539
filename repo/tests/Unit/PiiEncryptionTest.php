<?php

namespace Tests\Unit;

use App\Models\Employer;
use App\Models\MaskingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class PiiEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_employer_pii_fields_are_encrypted_at_rest(): void
    {
        $employer = Employer::factory()->create([
            'contact_last_name' => 'Johnson',
            'contact_phone' => '555-123-4567',
            'contact_email' => 'test@company.com',
            'street' => '123 Main St',
            'ein' => '12-3456789',
        ]);

        // Read raw from DB — should be encrypted (not plain text)
        $raw = \DB::table('employers')->where('id', $employer->id)->first();

        // The encrypted value should not match the plain text
        $this->assertNotEquals('Johnson', $raw->contact_last_name);
        $this->assertNotEquals('555-123-4567', $raw->contact_phone);
        $this->assertNotEquals('123 Main St', $raw->street);

        // Decrypting should return original value
        $this->assertEquals('Johnson', Crypt::decryptString($raw->contact_last_name));
        $this->assertEquals('555-123-4567', Crypt::decryptString($raw->contact_phone));
        $this->assertEquals('123 Main St', Crypt::decryptString($raw->street));
    }

    public function test_employer_get_decrypted_attribute(): void
    {
        $employer = Employer::factory()->create([
            'contact_last_name' => 'Smith',
            'contact_phone' => '555-987-6543',
        ]);

        // Reload from DB
        $employer->refresh();

        $this->assertEquals('Smith', $employer->getDecryptedAttribute('contact_last_name'));
        $this->assertEquals('555-987-6543', $employer->getDecryptedAttribute('contact_phone'));
    }

    public function test_employer_null_pii_fields_stay_null(): void
    {
        $employer = Employer::factory()->create([
            'contact_phone' => null,
            'street' => null,
        ]);

        $employer->refresh();
        $this->assertNull($employer->getDecryptedAttribute('contact_phone'));
        $this->assertNull($employer->getDecryptedAttribute('street'));
    }

    public function test_masking_for_admin_role_shows_unmasked(): void
    {
        // Create masking rules
        MaskingRule::firstOrCreate([
            'field_name' => 'contact_last_name',
            'mask_type' => 'first_initial',
            'visible_roles' => ['system_admin', 'compliance_reviewer'],
        ]);

        $employer = Employer::factory()->create([
            'contact_last_name' => 'Williams',
        ]);
        $employer->refresh();

        $masked = $employer->maskForRole('system_admin');
        $this->assertEquals('Williams', $masked['contact_last_name']);
    }

    public function test_masking_for_general_user_shows_masked(): void
    {
        MaskingRule::firstOrCreate([
            'field_name' => 'contact_last_name',
            'mask_type' => 'first_initial',
            'visible_roles' => ['system_admin', 'compliance_reviewer'],
        ]);
        MaskingRule::firstOrCreate([
            'field_name' => 'contact_phone',
            'mask_type' => 'last_four',
            'visible_roles' => ['system_admin'],
        ]);
        MaskingRule::firstOrCreate([
            'field_name' => 'contact_email',
            'mask_type' => 'partial_email',
            'visible_roles' => ['system_admin'],
        ]);
        MaskingRule::firstOrCreate([
            'field_name' => 'street',
            'mask_type' => 'redact',
            'visible_roles' => ['system_admin'],
        ]);
        MaskingRule::firstOrCreate([
            'field_name' => 'ein',
            'mask_type' => 'last_four',
            'visible_roles' => ['system_admin'],
        ]);

        $employer = Employer::factory()->create([
            'contact_last_name' => 'Williams',
            'contact_phone' => '555-123-4567',
            'contact_email' => 'john@example.com',
            'street' => '123 Main St',
            'ein' => '12-3456789',
        ]);
        $employer->refresh();

        $masked = $employer->maskForRole('general_user');

        $this->assertEquals('W.', $masked['contact_last_name']);
        $this->assertStringEndsWith('4567', $masked['contact_phone']);
        $this->assertStringStartsWith('j', $masked['contact_email']);
        $this->assertStringContainsString('*', $masked['contact_email']);
        $this->assertEquals('***REDACTED***', $masked['street']);
    }

    public function test_masking_with_null_role(): void
    {
        MaskingRule::firstOrCreate([
            'field_name' => 'contact_last_name',
            'mask_type' => 'first_initial',
            'visible_roles' => ['system_admin'],
        ]);

        $employer = Employer::factory()->create(['contact_last_name' => 'Davis']);
        $employer->refresh();

        $masked = $employer->maskForRole(null);
        $this->assertEquals('D.', $masked['contact_last_name']);
    }

    public function test_masking_falls_back_to_config_when_no_db_rule(): void
    {
        // No DB rule for 'contact_last_name' - should fall back to config
        $employer = Employer::factory()->create(['contact_last_name' => 'Brown']);
        $employer->refresh();

        $masked = $employer->maskForRole('general_user');
        // Config has visible_roles for last_name but 'contact_last_name' maps to it
        // Since no DB rule and no config match, should be redacted
        $this->assertNotEquals('Brown', $masked['contact_last_name']);
    }

    public function test_pii_fields_are_identified_on_employer(): void
    {
        $employer = new Employer();
        $piiFields = $employer->getPiiFields();

        $this->assertContains('contact_last_name', $piiFields);
        $this->assertContains('contact_phone', $piiFields);
        $this->assertContains('contact_email', $piiFields);
        $this->assertContains('street', $piiFields);
        $this->assertContains('ein', $piiFields);
    }
}
