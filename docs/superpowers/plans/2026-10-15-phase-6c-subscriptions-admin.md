# Phase 6c: Admin Subscriptions Oversight

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** The admin Subscriptions page from the user's design:

- **Header:** "Edit plans & prices" (→ Billing settings) and "Payments report" (CSV).
- **KPIs:** Active subscriptions (split by plan), Monthly recurring revenue, Failed payments, Cancelled this month (top reason).
- **Plans card:** with subscriber counts.
- **Needs attention:** failed or overdue subscriptions, with Email parent, Retry now and Pause access.
- **Recent payments table** and an **All payments page**.

**Builds on:** 6a and 6b (gateways, `ApplyGatewayEvent`, `GuardianNotice` from the lateness inbox).

## Global Constraints

- **Who:** everything is admin only (the `role:admin` group).
- **Money:** in naira (₦) from kobo.
- **Active subscriptions:** status Active or PastDue, counted per plan.
- **MRR:** the sum of the plan amounts of Active subscriptions that aren't cancelling, labelled "Before payment provider fees".
- **Failed payments KPI:** the number of PastDue subscriptions. The hint reads "Access pauses after {grace} days".
- **Cancelled this month:** subscriptions with `cancelled_at`, or a `cancel_at_period_end` set, in the current academy month. Because `cancel_at_period_end` has no timestamp, add `cancel_requested_at`, set by the cancel controller and by the `SubscriptionNotRenewing` and `SubscriptionCancelled` events. The note shows the top reason: `{n} gave "{reason}"`.
- **Needs attention:** PastDue subscriptions, oldest-failing first. Each row shows:
    - the payer, plan and the last failure: "card declined on 6 Oct · attempt {n}". Here n is the number of failed payments since the last success, and the reason is the failure reason in lower case.
    - "Emailed {date}" when a reminder has been sent.
- **Email parent:**
    - sends `PaymentFailedMail` to the payer (subject "Your Jerry Great Academy payment didn't go through", with a link to `/billing`);
    - creates a `GuardianNotice` for each covered child, so it shows on the guardian dashboard;
    - stores `payment_reminder_sent_at`.
    - It's refused (as a toast) if a reminder was sent in the last 24 hours, or if the payer has no email.
- **Retry now:**
    - only when the subscription has a saved card authorisation and is PastDue;
    - creates a Pending payment `JGA-<ULID>` for the plan amount;
    - calls `chargeSavedCard` on the subscription's own gateway:
        - Paystack: `POST /transaction/charge_authorization` with `{authorization_code, email, amount, reference, currency}`;
        - Flutterwave v3: `POST /tokenized-charges` with `{token, email, currency, amount(naira), tx_ref}`;
    - applies the result through `ApplyGatewayEvent` (so a success reactivates it and extends the period, and a failure records the reason);
    - toasts "Payment taken. {names} have access again." or "Card declined again: {reason}".
- **Pause access:** sets the status to Expired now. A later verified payment reactivates it, as `ApplyGatewayEvent` already does. It needs a confirm.
- **Recent payments:** the last 8 payments of any status, showing date, payer, plan, amount, method ("Visa ···· 4421") and status.
- **All payments** (`/admin/payments`): status, gateway and plan filters, search on payer name or email, from/to dates, paginated at 25, and the "Payments report" CSV export with the same filters. Cells are neutralised against formulas, as in the lateness export.
- **Nav:** an admin "Subscriptions" item (the `card` icon) with a badge for the PastDue count.

## Tasks

1. **Migration:**
    - adds `subscriptions.cancel_requested_at` and `payment_reminder_sent_at`;
    - fills `cancel_requested_at` from the cancel paths.
    - Tests: cancel paths set it.
2. **The `SubscriptionsOverview` service and the admin page** (`admin/subscriptions/index`), plus the nav item and badge.
    - Tests: KPIs, plans, attention rows, recent payments, admin-only.
3. **Actions:** Email parent (Mailable plus notice), Pause access, Retry now (the drivers' `chargeSavedCard`).
    - Tests:
        - mail sent and notice created, plus the 24-hour refusal;
        - pause revokes access;
        - retry succeeds and fails for both gateways (`Http::fake`, with the request body asserted);
        - no saved card;
        - non-PastDue refused.
4. **All payments page and CSV.**
    - Tests: filters, CSV, formula safety, admin-only.
5. **UI per the design**, with screenshots at 1440 and 360.

## Review Focus

1. Retry now goes to the subscription's own gateway, charges the plan amount in that gateway's units, and never extends twice on a repeated click (a new reference each click, but a disabled button while processing; the period is extended only once per successful charge).
2. Email parent can't be used to spam (24-hour limit).
3. Pause can't be undone by stale UI, and a successful payment afterwards restores access.
