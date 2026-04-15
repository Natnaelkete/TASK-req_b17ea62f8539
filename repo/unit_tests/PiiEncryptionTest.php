<?php

namespace Tests\UnitTests;

use App\Models\Employer;
use App\Models\MaskingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class PiiEncryptionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function employer_pii_fields_are_encrypted_at_rest(): void
    {
        $employer = Employer::factory()->create([
            'contact_last_name' => 'Johnson',
            'contact_phone' => '555-123-4567',
            'street' => '123 Main St',
        ]);

        $raw = \DB::table('employers')->where('id', $employer->id)->first();

        $this->assertNotEquals('Johnson', $raw->contact_last_name);
        $this->assertNotEquals('555-123-4567', $raw->contact_phone);
        $this->assertNotEquals('123 Main St', $raw->street);

        $this->assertEquals('Johnson', Crypt::decryptString($raw->contact_last_name));
        $this->assertEquals('555-123-4567', Crypt::decryptString($raw->contact_phone));
        $this->assertEquals('123 Main St', Crypt::decryptString($raw->street));
    }

    /** @test */
    public function get_decrypted_attribute_returns_original_value(): void
    {
        $employer = Employer::factory()->create([
            'contact_last_name' => 'Smith',
            'contact_phone' => '555-987-6543',
        ]);
        $employer->refresh();

        $this->assertEquals('Smith', $employer->getDecryptedAttribute('contact_last_name'));
        $this->assertEquals('555-987-6543', $employer->getDecryptedAttribute('contact_phone'));
    }

    /** @test */
    public function null_pii_fields_stay_null(): void
    {
        $employer = Employer::factory()->create([
            'contact_phone' => null,
            'street' => null,
        ]);
        $employer->refresh();
        $this->assertNull($employer->getDecryptedAttribute('contact_phone'));
        $this->assertNull($employer->getDecryptedAttribute('street'));
    }

    /** @test */
    public function pii_fields_are_explicitly_identified_on_employer(): void
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
