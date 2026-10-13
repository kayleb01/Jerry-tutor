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
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('attendee_role');
            $table->string('status');
            $table->dateTime('arrived_at')->nullable();
            $table->unsignedSmallInteger('minutes_late')->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['lesson_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('lateness_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained();
            $table->string('attendee_role');
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->string('class_name');
            $table->date('lesson_date')->index();
            $table->dateTime('scheduled_start_at');
            $table->dateTime('arrived_at');
            $table->unsignedSmallInteger('minutes_late');
            $table->text('reason')->nullable();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('follow_up_notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lateness_reports');
        Schema::dropIfExists('attendance_records');
    }
};
