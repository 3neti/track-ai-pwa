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
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->string('check_in_location_status')->nullable()->after('check_in_longitude');
            $table->json('check_in_location_evidence')->nullable()->after('check_in_location_status');
            $table->string('check_out_location_status')->nullable()->after('check_out_longitude');
            $table->json('check_out_location_evidence')->nullable()->after('check_out_location_status');
        });

        Schema::table('project_progress_reports', function (Blueprint $table) {
            $table->string('location_status')->nullable()->after('remarks');
            $table->json('location_evidence')->nullable()->after('location_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'check_in_location_status',
                'check_in_location_evidence',
                'check_out_location_status',
                'check_out_location_evidence',
            ]);
        });

        Schema::table('project_progress_reports', function (Blueprint $table) {
            $table->dropColumn([
                'location_status',
                'location_evidence',
            ]);
        });
    }
};
