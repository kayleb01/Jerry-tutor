<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves each class's tutor, weekly time and room into one Maths slot and points its lessons at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->foreignId('class_slot_id')->nullable()->after('class_group_id')->constrained('class_slots')->cascadeOnDelete();
        });

        $mathsId = $this->mathsSubjectId();

        DB::table('class_groups')->orderBy('id')->each(function (object $group) use ($mathsId): void {
            $slotId = DB::table('class_slots')->insertGetId([
                'class_group_id' => $group->id,
                'subject_id' => $mathsId,
                'tutor_id' => $group->tutor_id,
                'weekday' => $group->weekday,
                'start_time' => $group->start_time,
                'duration_minutes' => $group->duration_minutes,
                'location' => $group->location,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('lessons')->where('class_group_id', $group->id)->update(['class_slot_id' => $slotId]);
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->foreignId('class_slot_id')->nullable(false)->change();
            $table->unique(['class_slot_id', 'starts_at']);
        });

        Schema::table('class_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tutor_id');
            $table->dropColumn(['weekday', 'start_time', 'duration_minutes', 'location']);
        });
    }

    public function down(): void
    {
        Schema::table('class_groups', function (Blueprint $table) {
            $table->foreignId('tutor_id')->nullable()->after('name')->constrained('users')->nullOnDelete();
            $table->string('location')->nullable()->after('year_group');
            $table->unsignedTinyInteger('weekday')->nullable()->after('location');
            $table->time('start_time')->nullable()->after('weekday');
            $table->unsignedSmallInteger('duration_minutes')->default(60)->after('start_time');
        });

        DB::table('class_groups')->orderBy('id')->each(function (object $group): void {
            $slot = DB::table('class_slots')
                ->where('class_group_id', $group->id)
                ->orderByRaw('deleted_at is not null')
                ->orderBy('id')
                ->first();

            if ($slot === null) {
                return;
            }

            DB::table('class_groups')->where('id', $group->id)->update([
                'tutor_id' => $slot->tutor_id,
                'location' => $slot->location,
                'weekday' => $slot->weekday,
                'start_time' => $slot->start_time,
                'duration_minutes' => $slot->duration_minutes,
            ]);
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropUnique(['class_slot_id', 'starts_at']);
            $table->dropConstrainedForeignId('class_slot_id');
        });
    }

    /**
     * The Maths subject the subjects migration created, re-created if it has since been renamed or removed.
     */
    private function mathsSubjectId(): int
    {
        $id = DB::table('subjects')->where('name', 'Maths')->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        return DB::table('subjects')->insertGetId([
            'name' => 'Maths',
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
