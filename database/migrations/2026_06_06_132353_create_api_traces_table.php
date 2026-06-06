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
        Schema::create('api_traces', function (Blueprint $table) {
            $table->id();
            $table->string('trace_id')->nullable()->index();
            $table->string('provider')->default('saras')->index();
            $table->string('operation')->nullable()->index();
            $table->string('method', 10);
            $table->string('endpoint');
            $table->json('request_body')->nullable();
            $table->json('response_body')->nullable();
            $table->integer('status_code')->default(0)->index();
            $table->float('duration_ms')->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_traces');
    }
};
