<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_progress_reports', function (Blueprint $table) {
            $table->string('source')->default('local')->after('raw_saras_response');
            $table->timestamp('remote_deleted_at')->nullable()->after('source');
            $table->index(['source', 'remote_deleted_at']);
            $table->index('saras_process_id');
        });

        DB::table('project_progress_reports')
            ->whereNotNull('saras_process_id')
            ->update(['source' => 'saras']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_progress_reports', function (Blueprint $table) {
            $table->dropIndex(['source', 'remote_deleted_at']);
            $table->dropIndex(['saras_process_id']);
            $table->dropColumn(['source', 'remote_deleted_at']);
        });
    }
};
