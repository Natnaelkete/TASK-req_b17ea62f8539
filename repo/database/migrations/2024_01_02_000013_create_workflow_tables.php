<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('version')->default(1);
            $table->json('nodes'); // workflow node definitions with conditional branches
            $table->enum('approval_mode', ['all_approve', 'any_approve'])->default('all_approve');
            $table->unsignedInteger('timeout_hours')->default(48);
            $table->foreignId('escalation_role_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained()->onDelete('cascade');
            $table->string('entity_type'); // e.g., 'employer', 'result_version'
            $table->unsignedBigInteger('entity_id');
            $table->string('current_node')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'cancelled', 'escalated'])->default('pending');
            $table->foreignId('initiated_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->text('escalation_note')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_definitions');
    }
};
