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
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('contract_id'); // Saras contract reference
            $table->string('entry_id')->nullable(); // Saras entry ID, null until synced
            $table->string('remote_file_id')->nullable(); // Saras file ID
            $table->string('title');
            $table->text('remarks')->nullable();
            $table->string('document_type');
            $table->json('tags')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('status')->default('pending'); // pending|uploading|uploaded|failed|deleted
            $table->text('last_error')->nullable();
            $table->string('client_request_id')->unique(); // Idempotency key
            $table->timestamp('locked_at')->nullable();
            $table->string('locked_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('user_id');
            $table->index('status');
            $table->index(['contract_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
