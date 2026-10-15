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
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('key_stage')->nullable();
            $table->unsignedTinyInteger('year_group')->nullable()->index();
            $table->string('exam_track')->nullable()->index();
            $table->string('difficulty')->nullable();
            $table->foreignId('strand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('question_count')->default(10);
            $table->unsignedInteger('time_limit_seconds')->nullable();
            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('quiz_id')->constrained();
            $table->string('status')->index();
            $table->dateTime('started_at');
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedSmallInteger('max_score');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'quiz_id']);
        });

        Schema::create('quiz_attempt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained();
            $table->unsignedTinyInteger('position');
            $table->json('response')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->unsignedTinyInteger('marks_awarded')->default(0);
            $table->dateTime('answered_at')->nullable();
            $table->timestamps();
            $table->unique(['quiz_attempt_id', 'question_id']);
            $table->unique(['quiz_attempt_id', 'position']);
        });

        Schema::create('question_exposures', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('times_seen')->default(0);
            $table->dateTime('last_seen_at');
            $table->boolean('last_correct')->nullable();
            $table->primary(['user_id', 'question_id']);
            $table->index(['user_id', 'last_seen_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_exposures');
        Schema::dropIfExists('quiz_attempt_questions');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('quizzes');
    }
};
