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
        Schema::create('lesson_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users');
            $table->string('author_role');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('went_well')->nullable();
            $table->text('issues')->nullable();
            $table->text('recommendations')->nullable();
            $table->dateTime('submitted_at');
            $table->timestamps();

            $table->unique(['lesson_id', 'author_id']);
            $table->index(['author_role', 'submitted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lesson_reports');
    }
};
