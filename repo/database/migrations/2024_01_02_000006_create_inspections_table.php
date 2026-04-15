<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->onDelete('cascade');
            $table->foreignId('inspector_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('employer_id')->constrained()->onDelete('cascade');
            $table->enum('status', [
                'scheduled', 'in_progress', 'completed', 'cancelled', 'pending_sync',
            ])->default('scheduled');
            $table->dateTime('scheduled_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('findings')->nullable();
            $table->boolean('is_offline')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['inspector_id', 'status']);
            $table->index('job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspections');
    }
};
