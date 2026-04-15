<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('objections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_version_id')->constrained()->onDelete('cascade');
            $table->foreignId('filed_by')->constrained('users')->onDelete('cascade');
            $table->text('reason');
            $table->enum('status', ['intake', 'review', 'adjudication', 'resolved', 'dismissed'])->default('intake');
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['result_version_id', 'status']);
        });

        Schema::create('objection_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('objection_id')->constrained()->onDelete('cascade');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('objection_files');
        Schema::dropIfExists('objections');
    }
};
