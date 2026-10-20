<?php

use App\Enums\ApprovalStatus;
use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('approval_status')->nullable()->after('role');
        });

        DB::table('users')
            ->where('role', Role::Tutor->value)
            ->update(['approval_status' => ApprovalStatus::Approved->value]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('approval_status');
        });
    }
};
