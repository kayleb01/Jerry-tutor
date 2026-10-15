<?php

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['payments.default' => 'paystack', 'payments.paystack.secret_key' => 'sk_test']);
    $this->standard = Plan::factory()->create(['name' => 'Standard membership', 'slug' => 'standard', 'paystack_plan_code' => 'PLN_standard', 'max_students' => 1]);
    $this->family = Plan::factory()->family(2)->create(['paystack_plan_code' => 'PLN_family']);
    $this->parent = User::factory()->guardian()->create(['email' => 'ngozi@example.com']);
    $this->ada = User::factory()->student()->create(['name' => 'Ada']);
    $this->obi = User::factory()->student()->create(['name' => 'Obi']);
    $this->ife = User::factory()->student()->create(['name' => 'Ife']);
    foreach ([$this->ada, $this->obi, $this->ife] as $child) {
        $this->parent->linkChild($child, 'Mother');
    }
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(paymentFixture('paystack', 'initialize'))]);
});

function checkout(User $payer, Plan $plan, array $students)
{
    return test()->actingAs($payer)->post(route('billing.checkout'), ['plan' => $plan->id, 'students' => array_map(fn (User $student) => $student->id, $students)]);
}

it('starts a pending family subscription and sends the payer to the gateway', function () {
    checkout($this->parent, $this->family, [$this->ada, $this->obi])
        ->assertRedirect('https://checkout.paystack.com/0peioxfhpn');

    $this->actingAs($this->parent)
        ->post(route('billing.checkout'), ['plan' => $this->standard->id, 'students' => [$this->ife->id]], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://checkout.paystack.com/0peioxfhpn');

    $subscription = Subscription::where('plan_id', $this->family->id)->sole();
    $payment = $subscription->payments()->sole();

    expect($subscription->status)->toBe(SubscriptionStatus::Pending)
        ->and($subscription->payer_id)->toBe($this->parent->id)
        ->and($subscription->students->pluck('id')->all())->toEqualCanonicalizing([$this->ada->id, $this->obi->id])
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->amount)->toBe(1200000)
        ->and($payment->reference)->toStartWith('JGA-');

    Http::assertSent(fn (ClientRequest $request) => $request['reference'] === $payment->reference && $request['plan'] === 'PLN_family');
    expect(Payment::count())->toBe(2);
});

it('refuses selections the plan or family cannot cover', function (Closure $students, string $plan, string $message) {
    checkout($this->parent, $this->{$plan}, $students->call($this))
        ->assertSessionHasErrors(['students' => $message]);

    expect(Subscription::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'no children' => [fn () => [], 'standard', 'Choose at least one child.'],
    'too many for standard' => [fn () => [$this->ada, $this->obi], 'standard', 'The Standard membership covers one child.'],
    'too many for family' => [fn () => [$this->ada, $this->obi, $this->ife], 'family', 'The Family membership covers up to 2 children.'],
    'someone else\'s child' => [fn () => [User::factory()->student()->create()], 'standard', 'You can only subscribe for your own children.'],
]);

it('refuses a child who is already covered', function () {
    Subscription::factory()->active()->forStudents($this->ada)->create(['payer_id' => $this->parent->id]);

    checkout($this->parent, $this->standard, [$this->ada])
        ->assertSessionHasErrors(['students' => 'Ada already has a subscription.']);
});

it('replaces an abandoned checkout for the same child', function () {
    $stale = Subscription::factory()->pending()->forStudents($this->ada)->create(['payer_id' => $this->parent->id]);

    checkout($this->parent, $this->standard, [$this->ada])->assertRedirect('https://checkout.paystack.com/0peioxfhpn');

    expect($stale->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and(Subscription::where('status', SubscriptionStatus::Pending)->count())->toBe(1);
});

it('lets a student pay for themselves only', function () {
    checkout($this->ada, $this->standard, [$this->ada])->assertRedirect('https://checkout.paystack.com/0peioxfhpn');
    checkout($this->obi, $this->standard, [$this->ada])->assertSessionHasErrors(['students' => 'You can only subscribe for yourself.']);
});

it('says payments are unavailable when the plan is not linked to the gateway', function () {
    $this->standard->update(['paystack_plan_code' => null]);

    checkout($this->parent, $this->standard, [$this->ada])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Payments are unavailable right now. Please try again later.');

    expect(Subscription::count())->toBe(0);
});

it('keeps tutors and admins out of checkout', function (string $state) {
    checkout(User::factory()->{$state}()->create(), $this->standard, [$this->ada])->assertForbidden();
})->with(['tutor', 'admin']);

it('records a gateway failure instead of leaving a pending checkout', function () {
    Http::swap(new Factory);
    Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);

    checkout($this->parent, $this->standard, [$this->ada])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error');

    expect(Payment::sole()->status)->toBe(PaymentStatus::Failed)
        ->and(Subscription::sole()->status)->toBe(SubscriptionStatus::Cancelled);
});
