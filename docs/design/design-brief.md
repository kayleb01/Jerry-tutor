# Design Brief — Jerry Great Academy Platform

## 1. Client & product

**Client:** Jerry Great Academy (prepared for Mr. Caleb)
**Product:** One website with two connected modules:

1. **Attendance & Punctuality** — registers for every class, covering students _and_ tutors. It creates lateness reports automatically, and both sides can write end-of-class reports.
2. **Maths Quiz Platform (paid)** — maths-only quizzes for KS2 (Years 3–6) and KS3 (Years 7–9), plus 11+, Year 6 SATs and GCSE practice. Access is by monthly subscription.

## 2. Audiences

| Audience                  | Age / context                                         | What they need from the UI                                                                    |
| ------------------------- | ----------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| **Students (KS2)**        | 7–11, often on a tablet, sometimes with a parent      | Big tap targets, one question per screen, plain words, encouraging feedback, no clutter       |
| **Students (KS3 / GCSE)** | 11–16                                                 | Faster flow, progress over time, a feeling of a "grown-up" product rather than a childish one |
| **Parents / guardians**   | Adults who pay                                        | Clear pricing, trustworthy checkout, a view of their child's progress and attendance          |
| **Tutors**                | Staff, usually on a laptop during or just after class | Take the register in seconds, check in on time, submit end-of-class reports quickly           |
| **Admins**                | Academy management                                    | Timetable, lateness history, follow-up on repeat lateness, question bank, subscriptions       |

## 3. Brand

Taken from the supplied logo (crowned "JG" shield with the "JERRY GREAT ACADEMY" wordmark).

- **Royal indigo `#1B0063`**: authority, trust, academic. The primary colour.
- **Crown gold `#E49C24`**: achievement, reward, energy. The accent colour, used sparingly.
- **Personality:** _aspirational, warm, disciplined._ It should feel like a good private tutoring academy, not a game arcade and not a government portal.
- **Crown motif:** keep it for achievement moments (streaks, perfect scores, the _Challenging_ tier). Don't use it for decoration.

## 4. Design principles

1. **Fair and transparent.** Attendance and lateness records apply equally to students and tutors. The UI shows both sides with the same weight and wording.
2. **Fast for staff.** Taking a register or submitting a report takes under 30 seconds and works on one screen.
3. **Calm for learners.** Show one question at a time, with no countdown pressure by default. Wrong answers get a worked explanation, not a red wall.
4. **Status at a glance.** Attendance (on time, late, absent, excused) and difficulty (easy → challenging) always use the same colours and labels throughout the app.
5. **Accessible by default.** WCAG 2.2 AA contrast, full keyboard support, visible focus, and nothing that relies on colour alone (every status also has a text label).
6. **Mobile-first.** Students and parents use phones and tablets; tutor and admin views are laptop-first but must stay usable on a tablet.

## 5. Key experiences

### Attendance

- **Tutor "Today" view:** today's classes, a big _Check in_ button (its timestamp sets the tutor's punctuality), then the register. Each student can be marked On time, Late (with arrival time) or Absent.
- **Lateness report:** created automatically whenever anyone is marked late. It records who, which class, scheduled start, actual arrival and date. Admins see the history and a filter for repeat offenders.
- **End-of-class report:** a short form (rating, what went well, issues, recommendations). Tutor and student each submit their own, and both have the same fields.

### Quizzes

- **Student home:** "Your year" quizzes at the matched difficulty, a strand picker (Number, Algebra, Statistics, Geometry), exam prep tiles, and recent results.
- **Quiz player:** one question per screen, progress bar, number pad for numeric answers, then a results screen with explanations.
- **Paywall:** non-subscribers see the catalogue but can't start a quiz. The prompt is friendly and sends parents to checkout.

### Payments

- **Monthly subscription page:** one plan, a clear price in naira (e.g. ₦5,000 / month, shown with the ₦ symbol and thousands separators) and what's included. Payment happens on the Paystack or Flutterwave hosted checkout (whichever is active). A billing page lists each child's subscription, with renewal date and Cancel; on Paystack it also links to change the card.

## 6. Tone of voice

- Talk to students, not about them: "Nice work. 8 out of 10!", "Let's look at this one together."
- Keep staff copy factual and neutral: "Arrived 7 min after start", not "was late again".
- Use UK English and UK school terms: Year 5, KS2, maths, colour, enrol. Show prices in naira (₦).

## 7. Out of scope (v1)

- Native mobile apps (the responsive web app comes first).
- Live video classes. Classes take place in person or on an external tool, and this platform only records them.
- Subjects other than maths.
- AI-generated questions served live. Questions may be _authored_ with AI help, but every one is reviewed before it is published.

## 8. Success measures

- A tutor completes a register plus check-in in under 30 seconds.
- 100% of late arrivals have a lateness report (it is created automatically).
- A student sees no repeated question until they have used up that topic and difficulty's pool.
- Parents can subscribe in under 2 minutes from the paywall.
