<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->string('normalized_title'); // lowercase, trimmed for duplicate detection
            $table->text('description');
            $table->foreignId('category_id')->nullable()->constrained('job_categories')->nullOnDelete();
            $table->unsignedInteger('salary_min'); // USD
            $table->unsignedInteger('salary_max'); // USD
            $table->enum('education_level', [
                'high_school', 'associate', 'bachelor', 'master', 'doctorate',
            ]);
            // Structured US address
            $table->text('work_street')->nullable(); // PII - encrypted
            $table->string('work_city');
            $table->string('work_state', 2);
            $table->string('work_zip', 10);
            $table->enum('status', ['draft', 'active', 'closed', 'archived'])->default('draft');
            $table->boolean('is_offline')->default(false);
            $table->timestamps();

            $table->index(['employer_id', 'normalized_title', 'work_zip']);
            $table->index('status');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
