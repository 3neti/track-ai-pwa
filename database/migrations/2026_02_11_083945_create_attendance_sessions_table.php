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
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('project_external_id');

            // Check-in data
            $table->timestamp('check_in_at');
            $table->decimal('check_in_latitude', 10, 7);
            $table->decimal('check_in_longitude', 10, 7);
            $table->text('check_in_remarks')->nullable();

            // Check-out data (nullable until checkout occurs)
            $table->timestamp('check_out_at')->nullable();
            $table->decimal('check_out_latitude', 10, 7)->nullable();
            $table->decimal('check_out_longitude', 10, 7)->nullable();
            $table->text('check_out_remarks')->nullable();

            // Session status: open, closed, auto_closed
            $table->string('status')->default('open');
            $table->string('auto_closed_reason')->nullable(); // end_of_day, previous_day_unclosed

            $table->timestamps();

            // Indexes for efficient queries
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'project_external_id', 'status']);
            $table->index(['status', 'check_in_at']); // For auto-checkout queries
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_sessions');
    }
};
