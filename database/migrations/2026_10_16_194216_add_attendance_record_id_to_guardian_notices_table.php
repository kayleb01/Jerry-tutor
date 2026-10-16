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
        Schema::table('guardian_notices', function (Blueprint $table) {
            // One absence alert per attendance record, so it can be withdrawn if the absence is corrected.
            $table->foreignId('attendance_record_id')->nullable()->unique()->after('lateness_report_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guardian_notices', function (Blueprint $table) {
            $table->dropUnique(['attendance_record_id']);
            $table->dropConstrainedForeignId('attendance_record_id');
        });
    }
};
