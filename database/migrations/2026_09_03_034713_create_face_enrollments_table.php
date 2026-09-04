<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('face_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('hyperverge');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('face_enrollments');
    }
};
