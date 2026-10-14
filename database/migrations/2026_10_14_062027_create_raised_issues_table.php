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
        Schema::create('raised_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tutor_id')->constrained('users');
            $table->foreignId('raised_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note');
            $table->dateTime('acknowledged_at')->nullable();
            $table->timestamps();
            $table->index(['tutor_id', 'acknowledged_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('raised_issues');
    }
};
