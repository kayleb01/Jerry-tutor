<?php

use App\Enums\ApprovalStatus;
use App\Enums\Weekday;
use App\Mail\TutorApprovedMail;
use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\ClassSlot;
use App\Models\Subject;
use App\Models\TutorClassRequest;
use App\Models\User;
use App\Services\LessonScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->travelTo(CarbonImmutable::parse('2025-10-01 11:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
    $this->applicant = User::factory()->pendingTutor()->create(['name' => 'Bola Applicant', 'phone' => '08030000000']);
    $this->currentTutor = User::factory()->tutor()->create(['name' => 'Ma Rukayya']);
    $this->maths = Subject::query()->firstOrCreate(['name' => 'Maths']);
    $this->science = Subject::query()->firstOrCreate(['name' => 'Science']);
    $this->year5 = ClassGroup::factory()->create(['name' => 'Year 5']);
});

function applicationRequest(ClassGroup $group, Subject $subject, ?User $tutor = null): TutorClassRequest
{
    return TutorClassRequest::factory()->create([
        'tutor_id' => ($tutor ?? test()->applicant)->id,
        'class_group_id' => $group->id,
        'subject_id' => $subject->id,
    ]);
}

function applicationSlot(Subject $subject, ?User $tutor, Weekday $day = Weekday::Monday, string $start = '09:00', int $minutes = 120): ClassSlot
{
    return ClassSlot::factory()->create([
        'class_group_id' => test()->year5->id,
        'subject_id' => $subject->id,
        'tutor_id' => $tutor?->id,
        'weekday' => $day,
        'start_time' => $start,
        'duration_minutes' => $minutes,
    ]);
}

function approveApplication(array $decisions, ?User $tutor = null)
{
    return test()->actingAs(test()->admin)->post(route('admin.tutor-applications.approve', $tutor ?? test()->applicant), ['decisions' => $decisions]);
}

it('lists only verified pending applications, oldest first', function () {
    $this->applicant->forceFill(['created_at' => now()->subDay()])->save();
    applicationRequest($this->year5, $this->science);
    $older = User::factory()->pendingTutor()->create(['created_at' => now()->subDays(3)]);
    User::factory()->pendingTutor()->unverified()->create();
    $rejected = User::factory()->rejectedTutor()->create();
    User::factory()->tutor()->create();

    $this->actingAs($this->admin)->get(route('admin.tutor-applications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/tutor-applications/index')
            ->where('filters.status', 'pending')
            ->has('applications.data', 2)
            ->where('applications.data.0.id', $older->id)
            ->where('applications.data.1.id', $this->applicant->id)
            ->where('applications.data.1.classes', ['Year 5 · Science']));

    $this->actingAs($this->admin)->get(route('admin.tutor-applications.index', ['status' => 'rejected']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'rejected')
            ->has('applications.data', 1)
            ->where('applications.data.0.id', $rejected->id));
});

it('shows each requested class and subject with its slots and current tutors', function () {
    $science = applicationRequest($this->year5, $this->science);
    $year6 = ClassGroup::factory()->create(['name' => 'Year 6']);
    $english = applicationRequest($year6, Subject::query()->create(['name' => 'English']));
    $taught = applicationSlot($this->science, $this->currentTutor, Weekday::Monday, '12:00');
    $untaught = applicationSlot($this->science, null, Weekday::Wednesday, '09:00', 60);
    applicationSlot($this->science, $this->currentTutor, Weekday::Friday)->delete();
    applicationSlot($this->maths, $this->currentTutor, Weekday::Tuesday);

    $this->actingAs($this->admin)->get(route('admin.tutor-applications.show', $this->applicant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/tutor-applications/show')
            ->where('applicant.name', 'Bola Applicant')
            ->where('applicant.phone', '08030000000')
            ->where('applicant.status', 'pending')
            ->has('rows', 2)
            ->where('rows.0.requestId', $science->id)
            ->where('rows.0.label', 'Year 5 · Science')
            ->where('rows.0.slots', [
                ['slotId' => $taught->id, 'timeLabel' => 'Mon 12:00–14:00', 'currentTutor' => 'Ma Rukayya'],
                ['slotId' => $untaught->id, 'timeLabel' => 'Wed 09:00–10:00', 'currentTutor' => null],
            ])
            ->where('rows.1.requestId', $english->id)
            ->where('rows.1.label', 'Year 6 · English')
            ->where('rows.1.slots', [])
            ->has('weekdays', 7));
});

it('hands a slot over, moving only future unattended lessons to the new tutor', function () {
    $request = applicationRequest($this->year5, $this->science);
    $slot = applicationSlot($this->science, $this->currentTutor);
    app(LessonScheduler::class)->generateFor($slot);
    $next = $slot->lessons()->orderBy('starts_at')->first();
    AttendanceRecord::factory()->create(['lesson_id' => $next->id]);

    approveApplication([['request_id' => $request->id, 'slot_id' => $slot->id, 'action' => 'handover']])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.tutor-applications.index'))
        ->assertInertiaFlash('toast.type', 'success');

    $lessons = $slot->lessons()->orderBy('starts_at')->get();
    expect($slot->fresh()->tutor_id)->toBe($this->applicant->id)
        ->and($this->applicant->fresh()->approval_status)->toBe(ApprovalStatus::Approved)
        ->and($lessons)->toHaveCount(4)
        ->and($lessons->first()->id)->toBe($next->id)
        ->and($lessons->first()->tutor_id)->toBe($this->currentTutor->id)
        ->and($lessons->skip(1)->pluck('tutor_id')->unique()->all())->toBe([$this->applicant->id]);
});

it('leaves a slot with its tutor when asked', function () {
    $request = applicationRequest($this->year5, $this->science);
    $slot = applicationSlot($this->science, $this->currentTutor);
    app(LessonScheduler::class)->generateFor($slot);
    $lessonIds = $slot->lessons()->pluck('id')->all();

    approveApplication([['request_id' => $request->id, 'slot_id' => $slot->id, 'action' => 'leave']])
        ->assertSessionHasNoErrors();

    expect($slot->fresh()->tutor_id)->toBe($this->currentTutor->id)
        ->and($slot->lessons()->pluck('id')->all())->toBe($lessonIds)
        ->and($slot->lessons()->pluck('tutor_id')->unique()->all())->toBe([$this->currentTutor->id])
        ->and($this->applicant->fresh()->approval_status)->toBe(ApprovalStatus::Approved);
});

it('assigns an untaught slot and creates a new slot, generating lessons', function () {
    $science = applicationRequest($this->year5, $this->science);
    $maths = applicationRequest($this->year5, $this->maths);
    $untaught = applicationSlot($this->science, null);

    approveApplication([
        ['request_id' => $science->id, 'slot_id' => $untaught->id, 'action' => 'assign'],
        ['request_id' => $maths->id, 'slot_id' => null, 'action' => 'create', 'weekday' => Weekday::Tuesday->value, 'start_time' => '14:00', 'duration_minutes' => 90, 'location' => 'Room 3'],
    ])->assertSessionHasNoErrors();

    $created = $this->year5->slots()->where('subject_id', $this->maths->id)->sole();
    expect($untaught->fresh()->tutor_id)->toBe($this->applicant->id)
        ->and($untaught->lessons()->count())->toBe(4)
        ->and($untaught->lessons()->pluck('tutor_id')->unique()->all())->toBe([$this->applicant->id])
        ->and($created->tutor_id)->toBe($this->applicant->id)
        ->and($created->timeLabel())->toBe('Tue 14:00–15:30')
        ->and($created->location)->toBe('Room 3')
        ->and($created->lessons()->count())->toBe(4);
});

it('refuses a created slot that overlaps', function () {
    applicationSlot($this->maths, $this->currentTutor, Weekday::Monday, '09:00', 120);
    $request = applicationRequest($this->year5, $this->science);

    approveApplication([['request_id' => $request->id, 'slot_id' => null, 'action' => 'create', 'weekday' => Weekday::Monday->value, 'start_time' => '10:00', 'duration_minutes' => 60]])
        ->assertSessionHasErrors(['decisions.0.start_time' => 'Year 5 already has Maths 09:00–11:00 on Monday']);

    expect($this->year5->slots()->count())->toBe(1)
        ->and($this->applicant->fresh()->approval_status)->toBe(ApprovalStatus::Pending);
});

it('refuses two created slots that overlap each other', function () {
    $science = applicationRequest($this->year5, $this->science);
    $maths = applicationRequest($this->year5, $this->maths);
    $create = ['slot_id' => null, 'action' => 'create', 'weekday' => Weekday::Monday->value, 'duration_minutes' => 60];

    approveApplication([
        ['request_id' => $science->id, 'start_time' => '09:00', ...$create],
        ['request_id' => $maths->id, 'start_time' => '09:30', ...$create],
    ])->assertSessionHasErrors(['decisions.1.start_time' => 'Year 5 already has Science 09:00–10:00 on Monday']);

    expect($this->year5->slots()->count())->toBe(0);
});

it('approves with everything skipped', function () {
    $science = applicationRequest($this->year5, $this->science);
    $maths = applicationRequest($this->year5, $this->maths);
    $taught = applicationSlot($this->science, $this->currentTutor);
    $untaught = applicationSlot($this->science, null, Weekday::Friday);

    approveApplication([
        ['request_id' => $science->id, 'slot_id' => $taught->id, 'action' => 'leave'],
        ['request_id' => $science->id, 'slot_id' => $untaught->id, 'action' => 'skip'],
        ['request_id' => $maths->id, 'slot_id' => null, 'action' => 'skip'],
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.tutor-applications.index'));

    expect($this->applicant->fresh()->approval_status)->toBe(ApprovalStatus::Approved)
        ->and($untaught->fresh()->tutor_id)->toBeNull()
        ->and($this->year5->slots()->count())->toBe(2);
});

it('emails the tutor once approved', function () {
    Mail::fake();
    $request = applicationRequest($this->year5, $this->science);

    approveApplication([['request_id' => $request->id, 'slot_id' => null, 'action' => 'skip']]);

    Mail::assertSent(TutorApprovedMail::class, 1);
    Mail::assertSent(TutorApprovedMail::class, function (TutorApprovedMail $mail): bool {
        $mail->assertHasSubject("You're approved");
        $mail->assertSeeInText("You're approved — you can now log in and see your classes.");

        return $mail->hasTo($this->applicant->email);
    });
});

it('still approves the tutor when the approval email cannot be sent', function () {
    Exceptions::fake();
    Mail::shouldReceive('to->send')->once()->andThrow(new RuntimeException('Mail server is down'));
    $request = applicationRequest($this->year5, $this->science);

    approveApplication([['request_id' => $request->id, 'slot_id' => null, 'action' => 'skip']])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.tutor-applications.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "Bola Applicant is approved, but the email couldn't be sent."]);

    expect($this->applicant->fresh()->approval_status)->toBe(ApprovalStatus::Approved);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Mail server is down');
});

it('sends no email when the approval is refused', function () {
    Mail::fake();
    applicationRequest($this->year5, $this->science);

    approveApplication([])->assertSessionHasErrors('decisions');

    Mail::assertNothingSent();
});

it('rejects an application and can approve it later', function () {
    $request = applicationRequest($this->year5, $this->science);

    $this->actingAs($this->admin)->post(route('admin.tutor-applications.reject', $this->applicant))
        ->assertRedirect(route('admin.tutor-applications.index'))
        ->assertInertiaFlash('toast.type', 'success');

    expect($this->applicant->fresh()->approval_status)->toBe(ApprovalStatus::Rejected);

    $this->actingAs($this->admin)->get(route('admin.tutor-applications.show', $this->applicant))
        ->assertInertia(fn (Assert $page) => $page->where('applicant.status', 'rejected'));

    approveApplication([['request_id' => $request->id, 'slot_id' => null, 'action' => 'skip']])
        ->assertSessionHasNoErrors();

    expect($this->applicant->fresh()->approval_status)->toBe(ApprovalStatus::Approved);
});

it('cannot review an approved tutor or an unverified application', function (string $state) {
    $tutor = match ($state) {
        'approved' => User::factory()->tutor()->create(),
        'unverified' => User::factory()->pendingTutor()->unverified()->create(),
        'guardian' => User::factory()->guardian()->create(),
    };

    $this->actingAs($this->admin)->get(route('admin.tutor-applications.show', $tutor))->assertNotFound();
    approveApplication([], $tutor)->assertNotFound();
    $this->actingAs($this->admin)->post(route('admin.tutor-applications.reject', $tutor))->assertNotFound();

    expect($tutor->fresh()->approval_status)->toBe($tutor->approval_status);
})->with(['approved', 'unverified', 'guardian']);

it('refuses decisions that miss or invent a choice', function (Closure $decisions, string $field) {
    $science = applicationRequest($this->year5, $this->science);
    $maths = applicationRequest($this->year5, $this->maths);
    $taught = applicationSlot($this->science, $this->currentTutor);
    $untaught = applicationSlot($this->science, null, Weekday::Friday);
    $otherClassSlot = ClassSlot::factory()->create(['subject_id' => $this->science->id, 'tutor_id' => null]);
    $otherApplicantRequest = applicationRequest($this->year5, $this->science, User::factory()->pendingTutor()->create());

    approveApplication($decisions(compact('science', 'maths', 'taught', 'untaught', 'otherClassSlot', 'otherApplicantRequest')))
        ->assertSessionHasErrors($field);

    expect($this->applicant->fresh()->approval_status)->toBe(ApprovalStatus::Pending)
        ->and($taught->fresh()->tutor_id)->toBe($this->currentTutor->id)
        ->and($untaught->fresh()->tutor_id)->toBeNull();
})->with([
    'a slot left out' => [fn (array $m) => [
        ['request_id' => $m['science']->id, 'slot_id' => $m['taught']->id, 'action' => 'handover'],
        ['request_id' => $m['maths']->id, 'slot_id' => null, 'action' => 'skip'],
    ], 'decisions'],
    'a request left out' => [fn (array $m) => [
        ['request_id' => $m['science']->id, 'slot_id' => $m['taught']->id, 'action' => 'leave'],
        ['request_id' => $m['science']->id, 'slot_id' => $m['untaught']->id, 'action' => 'skip'],
    ], 'decisions'],
    'a slot from another class' => [fn (array $m) => [
        ['request_id' => $m['science']->id, 'slot_id' => $m['taught']->id, 'action' => 'leave'],
        ['request_id' => $m['science']->id, 'slot_id' => $m['untaught']->id, 'action' => 'skip'],
        ['request_id' => $m['science']->id, 'slot_id' => $m['otherClassSlot']->id, 'action' => 'assign'],
        ['request_id' => $m['maths']->id, 'slot_id' => null, 'action' => 'skip'],
    ], 'decisions.2.slot_id'],
    'another applicant\'s request' => [fn (array $m) => [
        ['request_id' => $m['science']->id, 'slot_id' => $m['taught']->id, 'action' => 'leave'],
        ['request_id' => $m['science']->id, 'slot_id' => $m['untaught']->id, 'action' => 'skip'],
        ['request_id' => $m['maths']->id, 'slot_id' => null, 'action' => 'skip'],
        ['request_id' => $m['otherApplicantRequest']->id, 'slot_id' => null, 'action' => 'skip'],
    ], 'decisions.3.request_id'],
    'the same slot twice' => [fn (array $m) => [
        ['request_id' => $m['science']->id, 'slot_id' => $m['taught']->id, 'action' => 'leave'],
        ['request_id' => $m['science']->id, 'slot_id' => $m['taught']->id, 'action' => 'handover'],
        ['request_id' => $m['science']->id, 'slot_id' => $m['untaught']->id, 'action' => 'skip'],
        ['request_id' => $m['maths']->id, 'slot_id' => null, 'action' => 'skip'],
    ], 'decisions.1.slot_id'],
    'assigning a taught slot' => [fn (array $m) => [
        ['request_id' => $m['science']->id, 'slot_id' => $m['taught']->id, 'action' => 'assign'],
        ['request_id' => $m['science']->id, 'slot_id' => $m['untaught']->id, 'action' => 'skip'],
        ['request_id' => $m['maths']->id, 'slot_id' => null, 'action' => 'skip'],
    ], 'decisions.0.action'],
    'handing over an untaught slot' => [fn (array $m) => [
        ['request_id' => $m['science']->id, 'slot_id' => $m['taught']->id, 'action' => 'leave'],
        ['request_id' => $m['science']->id, 'slot_id' => $m['untaught']->id, 'action' => 'handover'],
        ['request_id' => $m['maths']->id, 'slot_id' => null, 'action' => 'skip'],
    ], 'decisions.1.action'],
    'creating where slots exist' => [fn (array $m) => [
        ['request_id' => $m['science']->id, 'slot_id' => null, 'action' => 'create', 'weekday' => 3, 'start_time' => '15:00', 'duration_minutes' => 60],
        ['request_id' => $m['science']->id, 'slot_id' => $m['taught']->id, 'action' => 'leave'],
        ['request_id' => $m['science']->id, 'slot_id' => $m['untaught']->id, 'action' => 'skip'],
        ['request_id' => $m['maths']->id, 'slot_id' => null, 'action' => 'skip'],
    ], 'decisions.0.slot_id'],
    'creating without a day' => [fn (array $m) => [
        ['request_id' => $m['science']->id, 'slot_id' => $m['taught']->id, 'action' => 'leave'],
        ['request_id' => $m['science']->id, 'slot_id' => $m['untaught']->id, 'action' => 'skip'],
        ['request_id' => $m['maths']->id, 'slot_id' => null, 'action' => 'create', 'start_time' => '15:00', 'duration_minutes' => 60],
    ], 'decisions.2.weekday'],
    'an unknown action' => [fn (array $m) => [
        ['request_id' => $m['science']->id, 'slot_id' => $m['taught']->id, 'action' => 'steal'],
        ['request_id' => $m['science']->id, 'slot_id' => $m['untaught']->id, 'action' => 'skip'],
        ['request_id' => $m['maths']->id, 'slot_id' => null, 'action' => 'skip'],
    ], 'decisions.0.action'],
]);

it('shares the pending count with admins only', function () {
    User::factory()->pendingTutor()->unverified()->create();
    User::factory()->rejectedTutor()->create();

    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('pendingTutorCount', 1));

    $this->actingAs($this->currentTutor)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('pendingTutorCount', null));
});

it('is for admins only', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $request = applicationRequest($this->year5, $this->science);

    $this->actingAs($user)->get(route('admin.tutor-applications.index'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.tutor-applications.show', $this->applicant))->assertForbidden();
    $this->actingAs($user)->post(route('admin.tutor-applications.approve', $this->applicant), ['decisions' => [['request_id' => $request->id, 'slot_id' => null, 'action' => 'skip']]])->assertForbidden();
    $this->actingAs($user)->post(route('admin.tutor-applications.reject', $this->applicant))->assertForbidden();

    expect($this->applicant->fresh()->approval_status)->toBe(ApprovalStatus::Pending);
})->with(['tutor', 'guardian', 'student']);
