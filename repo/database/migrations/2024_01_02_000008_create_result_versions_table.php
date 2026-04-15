<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('result_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('version_number')->default(1);
            $table->enum('status', ['draft', 'internal', 'public'])->default('draft');
            $table->json('data'); // the result data payload
            $table->json('snapshot')->nullable(); // immutable snapshot when published
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['job_id', 'version_number']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_versions');
    }
};
