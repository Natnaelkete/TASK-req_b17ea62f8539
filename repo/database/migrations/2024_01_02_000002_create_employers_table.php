<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('company_name');
            $table->string('trade_name')->nullable();
            $table->string('ein')->nullable(); // PII - encrypted
            $table->string('contact_first_name');
            $table->text('contact_last_name'); // PII - encrypted
            $table->text('contact_phone')->nullable(); // PII - encrypted
            $table->text('contact_email');
            $table->text('street')->nullable(); // PII - encrypted
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->string('zip', 10)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'suspended'])->default('pending');
            $table->string('rejection_reason_code')->nullable();
            $table->text('rejection_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employers');
    }
};
