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
        Schema::create('project_progress_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('contract_id');
            $table->string('current_milestone')->nullable();
            $table->string('saras_process_id')->nullable();
            $table->string('saras_workflow_run_id')->nullable();
            $table->json('previous_progress_file_ids')->nullable();
            $table->json('current_progress_file_ids')->nullable();
            $table->text('remarks')->nullable();
            $table->string('progress_status')->default('draft');
            $table->string('completion_status')->nullable();
            $table->string('certificate_file_id')->nullable();
            $table->json('raw_saras_response')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'progress_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_progress_reports');
    }
};
