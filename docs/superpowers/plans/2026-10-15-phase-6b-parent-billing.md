# Phase 6b: Parent Billing and Paywall

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Parents (and students who pay for themselves) can manage subscriptions on a Billing page:

- see each child's quiz access;
- pick a plan and children, then subscribe;
- update the card (Paystack) or cancel with a reason;
- see their payment history.

Quiz pages get an access check that shows a paywall to students without access.

**Builds on:** Phase 6a: `StartCheckout`, `BillingCallbackController`, `CancelSubscriptionController`, `PaymentGatewayManager`, and `User::hasQuizAccess()`.

## Global Constraints

- **Who can use `/billing`:** guardians and students (`role:guardian,student`). A student sees and pays only for themselves; a guardian sees their linked children.
- **Plans offered:** active plans linked to the default gateway, ordered by `sort_order`.
    - With none, the page says "Payments aren't available yet. Please check back soon." and offers no subscribe form.
- **Children's states:**
    - Covered: by which plan, plus "Renews {date}", "Ends {date}" (cancelling) or "Payment failed, update your card" (past due).
    - Waiting: a pending checkout from today.
    - Not covered.
- **Subscribe:** only children who aren't covered can be ticked. The plan limits how many. The family-size and one-plan rules are enforced on the server (6a).
- **Subscription cards:**
    - Plan name, children, status, renewal or end date, and card brand plus last 4 digits.
    - Actions:
        - "Update card": Paystack's hosted manage link (`GET /subscription/{code}/manage/link`). On Flutterwave it explains that the parent should cancel and subscribe again with the new card.
        - "Cancel": reasons are "Exams finished", "Too expensive", "Not using it enough", "Moving to another tutor" and "Something else" (with free text, max 200 characters).
    - Only the payer can do either; anyone else gets a 403.
- **Payment history:** the payer's last 12 payments: date, plan, amount (₦), card, and status (Paid, Failed with the reason, Pending, Refunded).
- **Callback:** after verifying, it redirects to `billing.index`.
- **Paywall:** an `EnsureQuizAccess` middleware (alias `quiz.access`).
    - It lets in anyone with `hasQuizAccess()`.
    - Students without access get the `billing/paywall` Inertia page (status 402 is not used; it's 200 with the page). For a student with a guardian, it says "Ask {guardian} to subscribe on their Billing page." For a self-paying student, it links to Billing.
    - Guardians get a 403.
- **Navigation:** add a "Billing" item for guardians, and a "Billing" item for students who have no guardian (via a shared prop), so the sidebar stays static otherwise.
- **Local DB:** migrate only. Explicit staging. Pint, `check:fix` and `types:check`. Commit trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Tasks

1. **Billing page backend** (`BillingController@index` with props `plans`, `children`, `subscriptions`, `payments`, `available`, `cancelReasons`), plus the callback redirect change.
    - Tests:
        - guardian props: plan list, children's states, subscription labels, payments;
        - a student sees only themselves;
        - unavailable state;
        - tutor or admin gets 403.
2. **Update card:** `GET /billing/subscriptions/{subscription}/card`, with `PaystackGateway::manageLink()`.
    - Tests:
        - Paystack redirects to the link;
        - Flutterwave gets the toast;
        - non-payer gets 403;
        - a subscription with no code gets an explanatory toast.
3. **Paywall middleware and page.**
    - Tests use a test-only route: a student without access gets the paywall page with the right guidance; one with access passes; a tutor passes; a guardian gets 403.
4. **UI:**
    - The Billing page: a plan picker with a children checklist; subscription cards with Update card and a Cancel dialog; payment history.
    - The paywall page and the nav items.
    - Screenshots at 1440 and 360.

## Review Focus

1. A guardian can't see or act on another family's subscriptions (cancel, card).
2. Children who are already covered can't be ticked, and the server still refuses them.
3. The Billing page renders when no plans are linked, with no 500.
