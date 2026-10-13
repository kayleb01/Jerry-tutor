# Design System — Jerry Great Academy

The source of truth is [resources/css/app.css](../../resources/css/app.css), which uses Tailwind v4 CSS-first tokens. You can preview everything at `/styleguide` (that route only exists in the local environment).

## 1. Colour

### Brand scales (raw)

The colours were sampled from the logo. Use the `royal-*` and `gold-*` utilities, for example `bg-royal-800` or `text-gold-500`.

| Token       | Core value | Use                                            |
| ----------- | ---------- | ---------------------------------------------- |
| `royal-800` | `#1B0063`  | Primary brand: headers, primary buttons, links |
| `gold-500`  | `#E49C24`  | Accent: rewards, highlights, the subscribe CTA |

Rules for gold:

- **Never use gold for small text on white.** Its contrast is too low. Use it as a fill with `royal-950` text on top (that's the `accent` button), or use `gold-700` or darker for text.
- Use at most **one gold element per view**, so it keeps its meaning of "reward / go".

### Semantic tokens (use these in components)

These switch automatically in dark mode (`.dark` on `<html>`).

| Utility                                                         | Purpose                                              |
| --------------------------------------------------------------- | ---------------------------------------------------- |
| `bg-background`                                                 | Page background                                      |
| `bg-surface` / `bg-surface-muted`                               | Cards, panels / subtle fills, hover                  |
| `text-foreground` / `text-muted-foreground`                     | Body text / secondary text                           |
| `border-border` / `border-input`                                | Dividers / form controls                             |
| `bg-primary` `text-primary-foreground` `hover:bg-primary-hover` | Primary actions                                      |
| `bg-accent` `text-accent-foreground` `hover:bg-accent-hover`    | Reward / subscribe actions                           |
| `ring`                                                          | Focus outline (applied globally to `:focus-visible`) |
| `success` `warning` `danger` `info`                             | Feedback messages and toasts                         |

### Status palettes

Every status has a strong value and a `-soft` background. Badges use `bg-{x}-soft text-{x}`.

| Domain        | Tokens                                                                           |
| ------------- | -------------------------------------------------------------------------------- |
| Attendance    | `present`, `late`, `absent`, `excused`                                           |
| Difficulty    | `easy`, `intermediate`, `hard`, `challenging` (indigo on gold, the "crown" tier) |
| Maths strands | `strand-number`, `strand-algebra`, `strand-statistics`, `strand-geometry`        |

`late` is deliberately orange, not gold, so a lateness flag can never be confused with the brand's reward colour.

## 2. Typography

The fonts are loaded via Bunny Fonts in [vite.config.ts](../../vite.config.ts).

| Role               | Font                        | Utility               | Notes                                                                                                             |
| ------------------ | --------------------------- | --------------------- | ----------------------------------------------------------------------------------------------------------------- |
| Body / UI          | **Lexend** 400–700          | `font-sans` (default) | Designed to make reading easier, which suits young readers                                                        |
| Display            | **Saira Condensed** 600–700 | `font-display`        | Echoes the condensed logo wordmark. Use it for page titles and big scores only, usually `uppercase tracking-wide` |
| Numbers in quizzes | Lexend + `tabular-nums`     | —                     | Keeps answers and scores aligned                                                                                  |

Type scale: use Tailwind's default `text-xs` to `text-4xl`.

- **KS2 quiz player:** question stems at a minimum of `text-2xl`, answers at `text-xl`.
- **Staff tables:** `text-sm`.

## 3. Shape, elevation, spacing

- **Radius:** `rounded-sm` (6px) for small controls, `rounded-md` (10px) for buttons and inputs, `rounded-lg` (14px) for cards, `rounded-xl` (20px) for hero and quiz panels.
- **Shadow:** `shadow-card` (resting) and `shadow-raised` (popovers, the active quiz card). Both are indigo-tinted.
- **Spacing:** the default 4px Tailwind scale. Use `gap-*` between siblings, not margins.
- **Tap targets:** at least 40px (`h-10`) everywhere, and 48px (`h-12`, `size="lg"`) in the student quiz player.

## 4. Components

These live in [resources/js/components/ui/](../../resources/js/components/ui/).

| Component                                                              | Variants                                                                     |
| ---------------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| `Button`                                                               | `variant`: primary · accent · outline · ghost · danger; `size`: sm · md · lg |
| `Card` (+ `CardHeader`, `CardTitle`, `CardDescription`, `CardContent`) | —                                                                            |
| `Badge`                                                                | `tone`: neutral · brand · gold · each attendance status · each difficulty    |
| `Input`, `Label`                                                       | `aria-invalid` gives a danger border                                         |

There are also domain components in [resources/js/components/](../../resources/js/components/):

- `AppLogo`: a text lockup. Replace it with an SVG once the client supplies vector artwork.
- `AttendanceStatusBadge`: on time / late (with minutes) / absent / excused.
- `DifficultyBadge`: easy → challenging.

Components to add as features need them (see the implementation plan): Select, Textarea, Checkbox/Radio group, Dialog, Toast, Table, Tabs, Avatar, Progress bar, Empty state, Skeleton (needed for deferred props).

## 5. Accessibility checklist

- Text contrast is at least 4.5:1 (body) and 3:1 (large text and UI). The semantic tokens are chosen to pass on `surface` and `background`.
- Status is never shown by colour alone. Badges always carry a text label.
- Focus is visible on every interactive element (a global `:focus-visible` outline in `--ring`).
- Respect `prefers-reduced-motion`: celebratory animations must use `motion-safe:`.
- Form errors are linked with `aria-describedby`, and invalid inputs set `aria-invalid`.

## 6. Brand assets

- The full raster logo is at `public/images/brand/logo-full.jpg`.
- **To do:** request the SVG logo and a square crest mark from the client, for the favicon, the app icon and the nav.
