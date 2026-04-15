<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Workflow action audits - append-only
        Schema::create('workflow_action_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('actor_id');
            $table->string('role');
            $table->string('action'); // e.g., 'approve', 'reject', 'escalate', 'reassign'
            $table->string('prior_value_hash')->nullable();
            $table->string('new_value_hash')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('timestamp')->useCurrent();

            $table->index('workflow_instance_id');
            $table->index('actor_id');
        });

        // Result decision audits - append-only
        Schema::create('result_decision_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_version_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('actor_id');
            $table->string('role');
            $table->string('action'); // e.g., 'create_draft', 'publish_internal', 'publish_public'
            $table->string('prior_value_hash')->nullable();
            $table->string('new_value_hash')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('timestamp')->useCurrent();

            $table->index('result_version_id');
            $table->index('actor_id');
        });

        // Objection decision audits - append-only
        Schema::create('objection_decision_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('objection_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('actor_id');
            $table->string('role');
            $table->string('action'); // e.g., 'create', 'move_to_review', 'adjudicate', 'dismiss'
            $table->string('prior_value_hash')->nullable();
            $table->string('new_value_hash')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('timestamp')->useCurrent();

            $table->index('objection_id');
            $table->index('actor_id');
        });

        // Employer decision audits - append-only
        Schema::create('employer_decision_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('actor_id');
            $table->string('role');
            $table->string('action'); // e.g., 'approve', 'reject', 'suspend'
            $table->string('prior_value_hash')->nullable();
            $table->string('new_value_hash')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('timestamp')->useCurrent();

            $table->index('employer_id');
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employer_decision_audits');
        Schema::dropIfExists('objection_decision_audits');
        Schema::dropIfExists('result_decision_audits');
        Schema::dropIfExists('workflow_action_audits');
    }
};
