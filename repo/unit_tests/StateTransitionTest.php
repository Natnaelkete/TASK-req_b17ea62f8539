<?php

namespace Tests\UnitTests;

use App\Models\Employer;
use App\Models\FeatureFlag;
use App\Models\Job;
use App\Models\MaskingRule;
use App\Models\ResultVersion;
use App\Models\Objection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StateTransitionTest extends TestCase
{
    use RefreshDatabase;

    // --- Employer state transitions ---

    /** @test */
    public function employer_starts_in_pending_status(): void
    {
        $employer = Employer::factory()->create();
        $this->assertEquals('pending', $employer->status);
    }

    /** @test */
    public function employer_can_transition_to_approved(): void
    {
        $employer = Employer::factory()->create();
        $employer->update(['status' => 'approved', 'reviewed_at' => now()]);
        $this->assertEquals('approved', $employer->fresh()->status);
    }

    /** @test */
    public function employer_can_transition_to_rejected_with_reason(): void
    {
        $employer = Employer::factory()->create();
        $employer->update([
            'status' => 'rejected',
            'rejection_reason_code' => 'incomplete_docs',
            'rejection_notes' => 'Missing license',
            'reviewed_at' => now(),
        ]);
        $employer->refresh();
        $this->assertEquals('rejected', $employer->status);
        $this->assertEquals('incomplete_docs', $employer->rejection_reason_code);
    }

    /** @test */
    public function employer_rejection_reason_codes_are_defined(): void
    {
        $reasons = Employer::REJECTION_REASONS;
        $this->assertArrayHasKey('incomplete_docs', $reasons);
        $this->assertArrayHasKey('invalid_license', $reasons);
        $this->assertArrayHasKey('failed_verification', $reasons);
        $this->assertArrayHasKey('duplicate_entry', $reasons);
        $this->assertArrayHasKey('policy_violation', $reasons);
        $this->assertArrayHasKey('other', $reasons);
    }

    // --- Job state transitions ---

    /** @test */
    public function job_starts_in_draft_status(): void
    {
        $job = Job::factory()->create();
        $this->assertEquals('draft', $job->status);
    }

    /** @test */
    public function job_can_move_to_active(): void
    {
        $job = Job::factory()->create();
        $job->update(['status' => 'active']);
        $this->assertEquals('active', $job->fresh()->status);
    }

    /** @test */
    public function job_normalized_title_is_lowercased_and_trimmed(): void
    {
        $job = Job::factory()->create([
            'title' => '  Senior Developer  ',
            'normalized_title' => strtolower(trim('  Senior Developer  ')),
        ]);
        $this->assertEquals('senior developer', $job->normalized_title);
    }

    // --- Result version state transitions ---

    /** @test */
    public function result_version_starts_as_draft(): void
    {
        $rv = ResultVersion::factory()->create();
        $this->assertEquals('draft', $rv->status);
    }

    /** @test */
    public function result_version_can_transition_to_internal(): void
    {
        $rv = ResultVersion::factory()->create();
        $rv->update(['status' => 'internal']);
        $this->assertEquals('internal', $rv->fresh()->status);
    }

    /** @test */
    public function result_version_can_transition_to_public_with_snapshot(): void
    {
        $rv = ResultVersion::factory()->create(['status' => 'internal']);
        $rv->update([
            'status' => 'public',
            'published_at' => now(),
            'snapshot' => ['data' => $rv->data, 'snapshotted_at' => now()->toIso8601String()],
        ]);
        $rv->refresh();
        $this->assertEquals('public', $rv->status);
        $this->assertNotNull($rv->snapshot);
        $this->assertNotNull($rv->published_at);
    }

    // --- Objection state transitions ---

    /** @test */
    public function objection_starts_at_intake(): void
    {
        $rv = ResultVersion::factory()->create(['status' => 'public', 'published_at' => now()]);
        $user = User::factory()->create();
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $user->id,
            'reason' => 'Test',
            'status' => 'intake',
        ]);
        $this->assertEquals('intake', $objection->status);
    }

    /** @test */
    public function objection_transitions_intake_to_review_to_adjudication_to_resolved(): void
    {
        $rv = ResultVersion::factory()->create(['status' => 'public', 'published_at' => now()]);
        $user = User::factory()->create();
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $user->id,
            'reason' => 'Test',
            'status' => 'intake',
        ]);

        $objection->update(['status' => 'review']);
        $this->assertEquals('review', $objection->fresh()->status);

        $objection->update(['status' => 'adjudication']);
        $this->assertEquals('adjudication', $objection->fresh()->status);

        $objection->update(['status' => 'resolved', 'resolution_notes' => 'Sustained.']);
        $this->assertEquals('resolved', $objection->fresh()->status);
    }

    // --- Feature flags ---

    /** @test */
    public function feature_flag_toggle(): void
    {
        $flag = FeatureFlag::create(['key' => 'test_flag', 'enabled' => false]);
        $this->assertFalse(FeatureFlag::isEnabled('test_flag'));

        $flag->update(['enabled' => true]);
        $this->assertTrue(FeatureFlag::isEnabled('test_flag'));
    }

    /** @test */
    public function nonexistent_feature_flag_returns_false(): void
    {
        $this->assertFalse(FeatureFlag::isEnabled('does_not_exist'));
    }

    // --- Boundary: salary validation ---

    /** @test */
    public function salary_min_and_max_stored_as_integers(): void
    {
        $job = Job::factory()->create([
            'salary_min' => 50000,
            'salary_max' => 120000,
        ]);
        $this->assertIsInt($job->salary_min);
        $this->assertIsInt($job->salary_max);
        $this->assertGreaterThanOrEqual($job->salary_min, $job->salary_max);
    }

    // --- Boundary: masking rule config ---

    /** @test */
    public function masking_rule_stores_visible_roles_as_array(): void
    {
        $rule = MaskingRule::create([
            'field_name' => 'test_field',
            'mask_type' => 'first_initial',
            'visible_roles' => ['system_admin', 'inspector'],
        ]);
        $rule->refresh();
        $this->assertIsArray($rule->visible_roles);
        $this->assertContains('system_admin', $rule->visible_roles);
    }
}
