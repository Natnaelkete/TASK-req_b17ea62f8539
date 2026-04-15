<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->boolean('enabled')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('masking_rules', function (Blueprint $table) {
            $table->id();
            $table->string('field_name');
            $table->string('mask_type'); // first_initial, last_four, partial_email, redact, year_only
            $table->json('visible_roles'); // roles that can see unmasked data
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique('field_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masking_rules');
        Schema::dropIfExists('feature_flags');
    }
};
