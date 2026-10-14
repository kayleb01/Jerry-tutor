<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strand_id')->constrained()->restrictOnDelete();
            $table->string('key_stage');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['key_stage', 'slug']);
            $table->index(['key_stage', 'strand_id']);
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained()->restrictOnDelete();
            $table->string('difficulty')->index();
            $table->unsignedTinyInteger('year_group')->nullable()->index();
            $table->string('type');
            $table->text('stem');
            $table->string('image_path')->nullable();
            $table->decimal('numeric_answer', 12, 4)->nullable();
            $table->decimal('numeric_tolerance', 12, 4)->default(0);
            $table->json('accepted_answers')->nullable();
            $table->text('explanation')->nullable();
            $table->unsignedTinyInteger('marks')->default(1);
            $table->string('status')->index();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('times_served')->default(0);
            $table->unsignedInteger('times_correct')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['topic_id', 'difficulty', 'status']);
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_correct')->default(false);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['question_id', 'sort_order']);
        });

        Schema::create('exam_track_question', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('exam_track')->index();
            $table->unique(['question_id', 'exam_track']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_track_question');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('topics');
        Schema::dropIfExists('strands');
    }
};
