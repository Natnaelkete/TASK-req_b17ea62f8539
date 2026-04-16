<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_sync_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('idempotency_key')->unique();
            $table->string('device_id');
            $table->enum('status', [
                'queued', 'in_progress', 'succeeded', 'failed', 'quarantined',
            ])->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->json('payload')->nullable();
            $table->json('assembled_payload')->nullable(); // assembled from chunks
            $table->json('chunk_checksums')->nullable(); // per-chunk integrity tracking
            $table->unsignedInteger('total_chunks')->default(1);
            $table->unsignedInteger('received_chunks')->default(0);
            $table->timestamp('next_retry_at')->nullable(); // exponential backoff scheduling
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('idempotency_key');
            $table->index('next_retry_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_sync_batches');
    }
};
