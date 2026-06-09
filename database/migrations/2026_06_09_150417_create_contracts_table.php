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
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('saras_process_id')->unique();
            $table->string('name');
            $table->string('display_number')->nullable();
            $table->json('milestones')->nullable();
            $table->string('certificate_status')->default('not_started');
            $table->string('certificate_file_id')->nullable();
            $table->string('certificate_url')->nullable();
            $table->json('raw_saras_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
