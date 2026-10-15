# Phase 6a: Payments Foundation (Paystack + Flutterwave, Standard and Family plans)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the subscription engine behind the parent billing page (6b) and the admin Subscriptions page (6c):
- plans, subscriptions that cover one or more children, and payments;
- the Paystack and Flutterwave drivers;
- admin plan setup;
- checkout and a server-verified callback;
- idempotent webhooks that apply renewals, failures and cancellations;
- expiry and the quiz access rule.

**Architecture:** this follows `docs/architecture/database-schema.md` §6, with the changes from the user's decisions on 2026-10-15:
- **Two plans:** Standard covers 1 child, and Family covers up to `max_students` children at a flat price (default 4, editable by an admin).
- **Who a subscription covers:** a subscription can cover several children, so `subscriptions.student_id` becomes a `subscription_student` pivot. A child can be on at most one live subscription (Pending, Active or PastDue).
- **Saved card:** the subscription stores the reusable card authorisation (encrypted) plus the brand and last 4 digits, for "Retry now" and the payments table in 6c.
- **Gateway drivers:** `app/Payments` holds the `PaymentGateway` contract, the `PaymentGatewayManager` (`Illuminate\Support\Manager`), and the `PaystackGateway` and `FlutterwaveGateway` drivers. They call the REST APIs with Laravel `Http`, with no SDKs.
- **Event handling:** `ApplyGatewayEvent` is the only writer of payment or subscription state from a gateway. The callback and the webhook both go through it, and every step is idempotent on the payment `reference` or the webhook `event_key`.
- **Money:** amounts are integer **kobo** in **NGN** only. The Flutterwave driver converts to and from naira at its edge.

**Tech Stack:** Laravel 13 (`Http`, queues on the `database` connection, scheduler, Manager), Pest 5 with `Http::fake()` and JSON fixtures in `tests/Fixtures/payments/`.

**Spec:**
- `docs/architecture/database-schema.md` §6;
- `docs/plans/implementation-plan.md` Phase 6 (D2, D7);
- the user's answers on 2026-10-15: NGN; Standard plus Family plans; payments first; email plus an in-app note for failed payments (6c).

## Global Constraints

- **Currency and amounts:** NGN only, as integer kobo, with no float money anywhere. Paystack takes kobo; Flutterwave v3 takes and returns naira (`kobo / 100`; ×100 on the way in, rounding to an int). Any other currency fails verification.
- **Pinned APIs:** Paystack `https://api.paystack.co`; Flutterwave v3 `https://api.flutterwave.com/v3` only.
- **Plans live in the gateway dashboards.** Admin → Billing settings links a plan's Paystack plan code and Flutterwave plan id. On save, the app fetches each plan, and rejects it unless the amount equals the local amount, the currency is NGN, the interval is monthly, and the plan is active (not archived). Admins can edit a plan's display name and `max_students`, but not its amount (changing the price means creating a new gateway plan).
- **A gateway with no linked plans is unavailable.** Checkout shows "Payments unavailable" and doesn't throw. `payments.default` picks the gateway for new checkouts; existing subscriptions always use their own gateway.
- **Checkout:**
  - Only a guardian, for their own children, or a student paying for themselves.
  - The selection is 1 to `max_students` children.
  - None of the chosen children can already be on an Active or PastDue subscription. Their stale Pending ones are cancelled.
  - It creates a Pending subscription and payment with reference `JGA-<ULID>`, then returns the gateway's hosted URL.
- **Callback:** `GET /billing/callback/{gateway}?reference=…` verifies on the server. The query string alone is never trusted.
- **Webhooks:**
  - Endpoints: `POST /webhooks/paystack` (`x-paystack-signature` = HMAC-SHA512 of the raw body with the secret key) and `POST /webhooks/flutterwave` (`verif-hash` = the configured secret hash).
  - A bad signature gets 401 and nothing is stored.
  - A valid one is stored in `webhook_events`, unique on (`gateway`, `event_key`), so a duplicate does nothing. It is then applied on the queue.
- **Access:** admins and tutors always have quiz access. A student has it when they're on a subscription with status Active or PastDue and `current_period_ends_at + grace_days (7)` is in the future. `subscriptions:expire` (hourly) moves any subscription past that point to Expired.
- **Local DB:** `php artisan migrate` only. Stage explicit paths. Run Pint, `check:fix` and `types:check` before each commit. End commits with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Never grant access from unverified data.** A tampered reference, a failed verify, the wrong amount or currency, or a webhook with a bad signature leaves the subscription Pending. → Tasks 4 and 5.
2. **Callback and webhook in either order, or twice, give one payment and one period.** → Task 5.
3. **Family plan limits.** No children, more than `max_students`, another family's child, or a child already covered are all refused. → Task 4.
4. **Kobo and naira at the Flutterwave edge,** in both directions, including the amount check on verify. → Task 3.
5. **Cancel goes to the subscription's own gateway** after the default changes. Expiry respects the grace period. → Tasks 5 and 6.

---

### Task 1: Config, enums, schema, models, seeder
- **`config/payments.php`:**
  - `default` (`PAYMENT_GATEWAY`, `paystack`), `currency` (`NGN`), `grace_days` (7);
  - `paystack` with `secret_key` and `base_url`;
  - `flutterwave` with `secret_key`, `secret_hash` and `base_url`;
  - `plans` (the seeder defaults, from env: `PLAN_STANDARD_AMOUNT_KOBO` 500000, `PLAN_FAMILY_AMOUNT_KOBO` 1200000, `PLAN_FAMILY_MAX_STUDENTS` 4, plus each plan's codes).
- **`.env.example` keys.**
- **Enums:**
  - `PaymentGatewayName` (`paystack`, `flutterwave`) with `label()`;
  - `SubscriptionStatus` (`pending`, `active`, `past_due`, `cancelled`, `expired`) with `label()` and `isLive()` (Pending, Active or PastDue);
  - `PaymentStatus` (`pending`, `succeeded`, `failed`, `refunded`) with `label()`.
- **Migrations:**
  - `plans`: `id`, `name`, `slug` (unique), `description` (null), `amount` (unsigned int, kobo), `currency` (3), `interval`, `max_students` (tinyint, default 1), `paystack_plan_code` (null, unique), `flutterwave_plan_id` (null, unique), `is_active`, `sort_order`, timestamps.
  - `subscriptions`: `payer_id`, `plan_id`, `gateway`, `gateway_customer_code` (null), `gateway_subscription_code` (null, indexed), `gateway_email_token` (text, null), `gateway_authorization` (text, null), `card_brand` (null), `card_last4` (null), `status` (indexed), `current_period_ends_at` (null), `cancel_at_period_end`, `cancelled_at` (null), `cancellation_reason` (null), timestamps.
  - `subscription_student`: `subscription_id` (cascade), `student_id` (FK users, indexed), unique pair, timestamps.
  - `payments`: `subscription_id` (null), `payer_id`, `gateway`, `reference` (unique), `gateway_transaction_id` (null), `amount`, `currency`, `status`, `failure_reason` (null), `card_brand` (null), `card_last4` (null), `paid_at` (null), `gateway_response` (json, null), timestamps, index on (`status`, `created_at`).
  - `webhook_events`: `gateway`, `event_key`, `type`, `payload` json, `processed_at` (null), `error` (text, null), timestamps, unique on (`gateway`, `event_key`).
- **Models:**
  - `Plan`: `scopeActive`, `isLinkedTo(gateway)`, `codeFor(gateway)`, `formattedAmount()` (e.g. "₦5,000").
  - `Subscription`:
    - Casts: `gateway_email_token` and `gateway_authorization` encrypted.
    - Relations: `payer`, `plan`, `students` (belongsToMany with timestamps), `payments`.
    - Methods: `scopeLive`, `grantsAccessNow()`.
  - `Payment`, `WebhookEvent`.
  - `User`: `subscriptions()` (as payer), `coveringSubscriptions()` (as a student, via the pivot), and `hasQuizAccess()`.
- **Factories**, with states `active()`, `pastDue()`, `cancelled()`, `expired()` and `forStudents(...)`.
- **`PlanSeeder`:** Standard (max 1) and Family (max from config), idempotent on slug. Codes come from env when set. `DatabaseSeeder` calls it.
- **Tests (`tests/Feature/Payments/SubscriptionModelTest.php`):**
  - `hasQuizAccess` matrix: an admin or tutor always has access. A student is covered by Active; by PastDue within grace; not by PastDue past grace; not by Cancelled or Expired; and not when not covered.
  - A Family subscription covers several students.
  - Encrypted columns are not readable as plain text in the database.
  - The seeder is idempotent.

### Task 2: Gateway contract, DTOs, manager
- **`app/Payments`:**
  - `Contracts/PaymentGateway`:
    - `name(): PaymentGatewayName`;
    - `fetchPlan(string $code): GatewayPlan`;
    - `startCheckout(Subscription, Payment, string $callbackUrl): string` (returns the redirect URL);
    - `verify(string $reference): VerifiedPayment`;
    - `cancel(Subscription): void`;
    - `parseWebhook(Request): ?GatewayEvent`.
  - DTOs (readonly):
    - `GatewayPlan` (`code`, `amount` kobo, `currency`, `interval`, `active`);
    - `VerifiedPayment` (`reference`, `succeeded`, `amount` kobo, `currency`, `transactionId`, `customerCode`, `authorization`, `cardBrand`, `cardLast4`, `paidAt`, `raw`, `failureReason`);
    - `GatewayEvent` (`type` GatewayEventType, `eventKey`, `reference`, `subscriptionCode`, `customerCode`, `emailToken`, `amount`, `currency`, `periodEndsAt`, `authorization`, `cardBrand`, `cardLast4`, `failureReason`, `raw`).
  - `GatewayEventType`: `PaymentSucceeded`, `PaymentFailed`, `SubscriptionCreated`, `SubscriptionNotRenewing`, `SubscriptionCancelled`.
  - Exceptions: `GatewayException` (an HTTP failure or unexpected payload, carrying a message for the UI) and `InvalidWebhookSignature`.
  - `PaymentGatewayManager` extends `Manager`:
    - `getDefaultDriver()` returns `config('payments.default')`;
    - `createPaystackDriver()` and `createFlutterwaveDriver()`;
    - `for(Subscription)` returns `driver($subscription->gateway->value)`.
  - It is bound as a singleton.
- **Tests:** the manager resolves both drivers and the default from config, and `for()` uses the subscription's gateway even after the default changes.

### Task 3: Paystack and Flutterwave drivers
- **Paystack** (Bearer secret key, JSON):
  - `fetchPlan`: `GET /plan/{code}` → `data.amount`, `currency`, `interval`, and `active = !is_archived && !is_deleted`.
  - `startCheckout`: `POST /transaction/initialize` with `{email: payer email, amount, plan: plan code, reference, callback_url, metadata: {subscription_id}}` → `data.authorization_url`.
  - `verify`: `GET /transaction/verify/{reference}`:
    - `succeeded` is `data.status === 'success'`;
    - `customerCode` is `data.customer.customer_code`;
    - `authorization` is `data.authorization.authorization_code` when `reusable`;
    - `cardBrand` is `ucfirst(trim(card_type))`, and `cardLast4` is `last4`;
    - `failureReason` is `data.gateway_response`.
  - `cancel`: `POST /subscription/disable` with `{code, token: email_token}`.
  - `parseWebhook`:
    - Verify the HMAC-SHA512 signature first; on a mismatch, throw `InvalidWebhookSignature`.
    - `eventKey` is the sha256 of the raw body.
    - Event mapping:
      - `charge.success` with our `JGA-` reference → PaymentSucceeded (a renewal charge with another reference returns null, because `invoice.update` covers it);
      - `subscription.create` → SubscriptionCreated (`subscription_code`, `email_token`, `next_payment_date` → `periodEndsAt`, `customer_code`);
      - `invoice.update` with `paid` true → PaymentSucceeded (`reference` = `transaction.reference`, `subscriptionCode`, `periodEndsAt` = `subscription.next_payment_date`);
      - `invoice.payment_failed` → PaymentFailed (`subscriptionCode`, `failureReason`);
      - `subscription.not_renew` → SubscriptionNotRenewing;
      - `subscription.disable` → SubscriptionCancelled;
      - anything else → null.
- **Flutterwave v3** (Bearer secret key):
  - `fetchPlan`: `GET /payment-plans/{id}` → `amount×100`, `currency`, `interval`, `active = status === 'active'`.
  - `startCheckout`: `POST /payments` with `{tx_ref, amount: kobo/100, currency: NGN, redirect_url, payment_plan, customer: {email, name}, meta: {subscription_id}, customizations: {title: 'Jerry Great Academy'}}` → `data.link`.
  - `verify`: `GET /transactions/verify_by_reference?tx_ref=`:
    - `succeeded` is `data.status === 'successful'`;
    - `amount` is `round(data.amount × 100)`;
    - `customerCode` is `data.customer.id`;
    - `authorization` is `data.card.token`;
    - `cardBrand` is `ucfirst(strtolower(card.type))`, and `cardLast4` is `card.last_4digits`.
  - `cancel`: `PUT /subscriptions/{id}/cancel`.
  - `parseWebhook`:
    - Check `verif-hash` with `hash_equals`; on a mismatch, throw `InvalidWebhookSignature`.
    - Only `event === 'charge.completed'` is handled; `eventKey` is `data.id.':'.data.status`.
    - A successful charge → PaymentSucceeded (`reference` = `tx_ref`, `customerCode` = `customer.id`, card fields). A failed one → PaymentFailed (`failureReason` = `processor_response`).
    - Flutterwave's subscription id is filled in later by `findSubscriptionId(email, planId)` (`GET /subscriptions?email=`, which picks the newest active subscription on the plan). `ApplyGatewayEvent` calls it after the first success, so it's a public method on the Flutterwave driver only.
- **Tests:**
  - Recorded fixtures in `tests/Fixtures/payments/{paystack,flutterwave}/*.json`, with `Http::fake` keyed by URL.
  - A Pest dataset runs both drivers through the shared behaviours:
    - fetching a plan;
    - the start-checkout request body (`Http::assertSent`), which carries `500000` for Paystack and `5000` for Flutterwave;
    - verify, which maps the amount to kobo;
    - cancel, which hits the right endpoint;
    - a bad signature, which throws;
    - parsing each event type;
    - an HTTP 500, which becomes a `GatewayException`.

### Task 4: Billing settings and StartCheckout
- **Admin → Billing settings** (`/admin/billing`, admin-only):
  - It lists the plans: name, amount, max children, each gateway's link state, and active.
  - `PUT /admin/billing/plans/{plan}` updates the name, description and `max_students` (between 1 and 10; the Standard plan is fixed at 1), the Paystack code, the Flutterwave id, and `is_active`.
  - Saving a code calls `fetchPlan` and validates the amount, NGN, monthly and active. Each failure gets a specific error on that field:
    - "That plan is ₦6,000 a month in Paystack, but this plan is ₦5,000."
    - "That plan isn't in naira."
    - "That plan doesn't renew monthly."
    - "That plan is archived."
    - "Paystack doesn't recognise that plan code."
  - The page also shows which gateway is the default, and whether it's ready.
- **`App\Actions\StartCheckout::handle(User $payer, Plan $plan, Collection $students, ?PaymentGatewayName $gateway = null): string`:**
  - Its rules are listed under Global Constraints.
  - It throws `CheckoutUnavailable` (a "Payments unavailable" message) when the gateway has no code for the plan or the plan is inactive.
  - It throws a `ValidationException` (on `students`) for every rule broken.
  - The DB work (the subscription, the pivot, and a Pending payment for the plan amount) runs in a transaction. The gateway call happens after it, and a gateway failure marks the payment Failed with the reason.
- **Routes:**
  - `POST /billing/checkout` (auth: guardian or student, with `plan` and `students[]`) → `Inertia::location($url)`.
  - The page itself comes in 6b; this task tests the endpoint only.
- **Tests:**
  - Billing settings saves, rejecting each mismatch with its message (with `Http::fake`), and is admin-only.
  - StartCheckout:
    - creates a pending subscription, payment and pivot, then redirects to the faked URL;
    - refuses no children; over the max; another family's child (403 or 422); a covered child; and an inactive or unlinked plan ("Payments unavailable");
    - a student can buy for themselves only;
    - a stale pending subscription for the same child is cancelled.

### Task 5: Callback, webhooks, ApplyGatewayEvent
- **`App\Actions\ApplyGatewayEvent::handle(PaymentGatewayName, GatewayEvent): void`**, in a transaction with `lockForUpdate` on the payment and subscription. By event type:
  - **PaymentSucceeded with a known reference:**
    - If the payment isn't Succeeded yet: check the amount equals the plan amount and the currency is NGN. On a mismatch, mark the payment Failed with "Amount or currency did not match the plan" and stop.
    - Mark the payment Succeeded, with `paid_at`, the transaction id, the card details and the raw payload.
    - The subscription becomes Active and stores the customer code, the authorisation and the card details.
    - `current_period_ends_at` becomes `event.periodEndsAt` if given, otherwise the later of (now, the current end) plus 1 month.
    - Flutterwave: if the subscription code is missing, call `findSubscriptionId`.
  - **PaymentSucceeded with an unknown reference** (a renewal):
    - Find the subscription by subscription code, otherwise by gateway plus customer code (with a live status).
    - Create a Succeeded payment with that reference and the plan amount, then extend the period as above.
    - If no subscription is found, log the event and store an error on it.
  - **PaymentFailed:**
    - With a known reference, mark that payment Failed.
    - Otherwise, create a Failed payment `{gateway}-failed-{eventKey}` for the matched subscription.
    - The subscription becomes PastDue if it was Active.
  - **SubscriptionCreated:** store the code and email token; set the period end if given.
  - **SubscriptionNotRenewing:** `cancel_at_period_end = true`.
  - **SubscriptionCancelled:** the status becomes Cancelled and `cancelled_at` is set (access still runs to the period end plus grace).
- **`GET /billing/callback/{gateway}`:** `verify(reference)` turns the result into a PaymentSucceeded or PaymentFailed event with `eventKey` `callback:{reference}`, and applies it straight away. It then redirects to `/billing` (6b; until then `dashboard`) with a toast: "You're subscribed." or "That payment didn't go through: {reason}". An unknown reference gets 404.
- **Webhooks:**
  - The routes sit outside the `web` CSRF middleware (`withoutMiddleware(VerifyCsrfToken)`), with `throttle:120,1`.
  - The driver parses the request; a bad signature gets 401.
  - An irrelevant event gets 200 `ignored`.
  - Otherwise, `WebhookEvent::firstOrCreate` on (`gateway`, `event_key`). If it was already processed, return 200; if it's new, dispatch `ProcessWebhookEvent` and return 200.
  - The job rebuilds the event from the stored payload, applies it, and sets `processed_at`, or stores the error and rethrows to retry. It runs with `tries=5` and backoff.
- **`POST /billing/subscriptions/{subscription}/cancel`** (the payer only): `manager->for($subscription)->cancel()`, then `cancel_at_period_end = true` and `cancellation_reason` (optional, max 200). The UI comes in 6b.
- **Tests:**
  - Callback:
    - success activates the subscription and sets the period;
    - failure doesn't;
    - the wrong amount doesn't (the payment is Failed);
    - an unknown reference gets 404.
  - Order: callback then webhook, and webhook then callback, give one Succeeded payment and the same period.
  - A duplicate webhook is a no-op.
  - Bad signatures get 401, for both gateways.
  - Renewal (Paystack `invoice.update`, and a Flutterwave `charge.completed` with a new `tx_ref`) extends the period by one month and records a payment.
  - A failed renewal leads to PastDue.
  - `not_renew` sets `cancel_at_period_end`; `disable` sets Cancelled.
  - Cancel uses the subscription's own gateway after the default is switched.
  - Cancel by someone who isn't the payer gets 403.
  - Run the queue `sync` in tests.

### Task 6: Expiry, scheduling, docs
- **`subscriptions:expire`:**
  - Live subscriptions whose `current_period_ends_at + grace` has passed → Expired.
  - Pending subscriptions older than 24 hours → Cancelled (abandoned checkouts).
  - It's scheduled hourly in `routes/console.php`.
- **Tests:** expiry at the grace edge, plus the abandoned pending case.
- **Docs:** update `docs/architecture/database-schema.md` §6 for the pivot, `max_students`, the card and authorisation columns, and `cancellation_reason`.

## After the last task
Run the full suite and the final whole-branch review, with a fresh reviewer focused on payment safety.
