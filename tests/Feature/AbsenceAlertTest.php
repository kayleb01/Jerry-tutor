<?php

use App\Enums\AttendanceStatus;
use App\Enums\YearGroup;
use App\Mail\AbsenceMail;
use App\Models\ClassGroup;
use App\Models\GuardianNotice;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:30:00', 'UTC'));
    $this->tutor = User::factory()->tutor()->create(['name' => 'Tunde Tutor']);
    $this->group = ClassGroup::factory()->withSlot($this->tutor)->create(['name' => 'Year 5 Maths']);
    $this->ava = User::factory()->student(YearGroup::Year5)->create(['name' => 'Ava Obi']);
    $this->bola = User::factory()->student(YearGroup::Year5)->create(['name' => 'Bola Ade']);
    $this->group->enrol($this->ava);
    $this->group->enrol($this->bola);
    $this->mum = User::factory()->guardian()->create(['name' => 'Ngozi Obi', 'email' => 'ngozi@example.com']);
    $this->dad = User::factory()->guardian()->create(['name' => 'Emeka Obi', 'email' => null]);
    $this->mum->linkChild($this->ava);
    $this->dad->linkChild($this->ava);
    $this->lesson = Lesson::factory()->for($this->group)->create([
        'tutor_id' => $this->tutor->id,
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);
    $this->save = fn (array $attendance, ?User $by = null) => $this->actingAs($by ?? $this->tutor)
        ->put(route('lessons.register.update', $this->lesson), ['attendance' => $attendance]);
});

it('tells the parents when a saved register marks their child absent', function () {
    ($this->save)([$this->ava->id => ['status' => 'absent'], $this->bola->id => ['status' => 'present']])
        ->assertSessionHasNoErrors();

    $notice = GuardianNotice::sole();
    expect($notice->student_id)->toBe($this->ava->id)
        ->and($notice->attendance_record_id)->toBe($this->lesson->attendanceRecords()->where('user_id', $this->ava->id)->value('id'))
        ->and($notice->sent_by_id)->toBe($this->tutor->id)
        ->and($notice->note)->toBe('Ava was absent from Year 5 Maths on Sat 4 Oct.');

    Mail::assertSentCount(1);
    Mail::assertSent(AbsenceMail::class, fn (AbsenceMail $mail) => $mail->hasTo('ngozi@example.com')
        && $mail->hasSubject('Ava was absent from Year 5 Maths')
        && $mail->guardian->is($this->mum));
});

it('alerts the parents once, however often the register is saved', function () {
    ($this->save)([$this->ava->id => ['status' => 'absent']]);
    ($this->save)([$this->ava->id => ['status' => 'absent']]);
    ($this->save)([$this->ava->id => ['status' => 'absent', 'notes' => 'Unwell']], User::factory()->admin()->create());

    expect(GuardianNotice::count())->toBe(1);
    Mail::assertSentCount(1);
});

it('withdraws an unread absence note when the child turns out to be there', function () {
    ($this->save)([$this->ava->id => ['status' => 'absent']]);
    ($this->save)([$this->ava->id => ['status' => 'late', 'arrived_at' => '10:20']]);

    expect(GuardianNotice::count())->toBe(0);
});

it('keeps an absence note the parent has already read', function () {
    ($this->save)([$this->ava->id => ['status' => 'absent']]);
    GuardianNotice::sole()->update(['acknowledged_at' => now(), 'acknowledged_by_id' => $this->mum->id]);
    ($this->save)([$this->ava->id => ['status' => 'present']]);

    expect(GuardianNotice::count())->toBe(1);
});

it('alerts nobody for a child with no parent linked, or for an absent tutor', function () {
    ($this->save)([
        $this->bola->id => ['status' => 'absent'],
        $this->tutor->id => ['status' => 'absent'],
    ], User::factory()->admin()->create())->assertSessionHasNoErrors();

    expect(GuardianNotice::count())->toBe(0);
    Mail::assertNothingSent();
});

it('does not alert parents when the no-show job marks an untaken register', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 11:00:00', 'UTC'));

    $this->artisan('attendance:mark-no-shows')->assertSuccessful();

    expect($this->lesson->attendanceRecords()->where('user_id', $this->ava->id)->value('status'))->toBe(AttendanceStatus::Absent)
        ->and(GuardianNotice::count())->toBe(0);
    Mail::assertNothingSent();
});
