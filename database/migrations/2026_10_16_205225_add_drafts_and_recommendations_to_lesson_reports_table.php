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
        Schema::table('lesson_reports', function (Blueprint $table) {
            $table->dateTime('submitted_at')->nullable()->change();
            $table->json('recommended_actions')->nullable();
            $table->boolean('raises_issue')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lesson_reports', function (Blueprint $table) {
            $table->dropColumn(['recommended_actions', 'raises_issue']);
        });

        DB::table('lesson_reports')->whereNull('submitted_at')->update(['submitted_at' => DB::raw('created_at')]);

        Schema::table('lesson_reports', function (Blueprint $table) {
            $table->dateTime('submitted_at')->nullable(false)->change();
        });
    }
};
