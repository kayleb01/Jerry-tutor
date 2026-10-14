# Design System — Jerry Great Academy

The source of truth is [resources/css/app.css](../../resources/css/app.css), which uses Tailwind v4 CSS-first tokens. It implements the **Jerry Great Academy design system** (brand board + logo), published as a Design System artifact in Claude. You can preview everything at `/styleguide` (that route only exists in the local environment).

## 1. Colour

### Tonal scales (raw)

Four Material-style tonal scales, each with steps `10`–`95` plus its key colour (`-key`). Use them as `bg-primary-90`, `text-secondary-40`, `bg-tertiary-30`, `border-neutral-80` and so on. Tailwind's own `neutral` palette is replaced by the brand's lilac-tinted greys.

| Scale       | Key       | Use                                                       |
| ----------- | --------- | --------------------------------------------------------- |
| `primary`   | `#1E0B54` | Deep indigo: the brand voice — hero bands, headers, tints |
| `secondary` | `#E5A024` | Academy gold: highlights and the accent button            |
| `tertiary`  | `#2E1065` | Violet-indigo: supporting shapes and the Algebra strand   |
| `neutral`   | `#F3F0FA` | Lilac-tinted greys the surfaces and text are built from   |

`logo-indigo` (`#140063`) and `logo-gold` (`#E79100`) are the logo's exact inks — brand marks only, never UI.

Rules:

- **The main action colour is near-black (`primary`, `#010009`), not indigo.** Primary buttons and the active menu item use it. Indigo (`bg-brand`) is for hero bands, headers and cards that carry the brand.
- **Never use gold as text on light grounds** (2.2:1 on white). Use it as a fill with `primary-10` text (the `accent` button), or use `secondary-40` or darker for text.
- The logo only ever sits on a light ground. `AppLogoImage` puts it on a white panel in dark mode or with `onDark`.

### Semantic tokens (use these in components)

These switch automatically in dark mode (`.dark` on `<html>`). The light values come from the design system; the dark theme is derived from the same scales.

| Utility                                                         | Purpose                                                  |
| --------------------------------------------------------------- | -------------------------------------------------------- |
| `bg-background`                                                 | Page background (`#EFECF6`)                              |
| `bg-surface` / `bg-surface-muted`                               | Cards (white) / recessed fills (secondary button, track) |
| `text-foreground` / `text-muted-foreground`                     | Headings and key text / body copy and labels             |
| `border-border` / `border-input`                                | Hairlines / form-control borders (3:1)                   |
| `bg-primary` `text-primary-foreground` `hover:bg-primary-hover` | Primary actions (near-black)                             |
| `bg-brand` `text-brand-foreground`                              | Indigo hero bands and brand cards                        |
| `text-link`                                                     | Text links (`primary-40`)                                |
| `bg-accent` `text-accent-foreground` `hover:bg-accent-hover`    | Gold accent actions (check in, subscribe)                |
| `ring`                                                          | Focus outline (applied globally to `:focus-visible`)     |
| `success` `warning` `danger` `info`                             | Feedback messages and toasts                             |

### Status palettes

Every status has a strong value and a `-soft` background. Badges use `bg-{x}-soft text-{x}`, and always carry a word (and, for attendance, an icon).

| Domain        | Tokens                                                                                             |
| ------------- | -------------------------------------------------------------------------------------------------- |
| Attendance    | `present` (indigo tint), `late` (gold tint), `absent` (red), `excused` (grey)                      |
| Difficulty    | `easy` → `hard` deepen in indigo; `challenging` is indigo on gold. `DifficultyBadge` adds 1–4 pips |
| Maths strands | `strand-number`, `strand-algebra`, `strand-statistics`, `strand-geometry`                          |

## 2. Typography

**Plus Jakarta Sans** (400–800) for every role, loaded via Bunny Fonts in [vite.config.ts](../../vite.config.ts). `font-display` is an alias of the same family. Use sentence case for headings; the condensed lettering lives in the logo file only.

| Role          | Size / line height                  | Weight |
| ------------- | ----------------------------------- | ------ |
| Page title    | 30 / 38                             | 700    |
| Section title | 24 / 32                             | 700    |
| Card title    | 18 / 26                             | 700    |
| Body          | 14–16 / 20–24                       | 400    |
| Label         | 14 / 20                             | 500    |
| Eyebrow       | 12 / 16, uppercase, 0.08em tracking | 600    |

- **KS2 quiz player:** question stems at a minimum of `text-2xl`, answers at `text-xl`.
- Numbers in quizzes and stats use `tabular-nums`.

## 3. Shape, elevation, spacing

- **Radius:** `rounded-sm` (4px) for buttons and inputs, `rounded-md` (8px) for badges and small panels, `rounded-lg` (16px) for cards, `rounded-xl` (20px) for hero and quiz panels.
- **Elevation:** cards are separated from the page by tone and a hairline, not shadow (`shadow-card` is intentionally empty). `shadow-raised` is for floating things only.
- **Spacing:** the default 4px Tailwind scale. Use `gap-*` between siblings, not margins.
- **Tap targets:** at least 40px (`h-10`) everywhere, and 48px (`h-12`, `size="lg"`) in the student quiz player.

## 4. Components

These live in [resources/js/components/ui/](../../resources/js/components/ui/).

| Component                                                              | Variants                                                                                 |
| ---------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| `Button` (+ `buttonClasses()` for links)                               | `variant`: primary · accent · secondary · outline · ghost · danger; `size`: sm · md · lg |
| `Card` (+ `CardHeader`, `CardTitle`, `CardDescription`, `CardContent`) | —                                                                                        |
| `Badge`                                                                | `tone`: neutral · brand · gold · each attendance status · each difficulty                |
| `Input`, `Label`                                                       | `aria-invalid` gives a danger border                                                     |

There are also domain components in [resources/js/components/](../../resources/js/components/):

- `AppLogo` / `AppLogoImage`: the transparent PNG logo (full lockup or crest).
- `AttendanceStatusBadge`: on time / late (with minutes) / absent / excused.
- `DifficultyBadge`: easy → challenging, with level pips.

Components to add as features need them (see the implementation plan): Select, Textarea, Checkbox/Radio group, Dialog, Toast, Table, Tabs, Avatar, Progress bar, Empty state, Skeleton (needed for deferred props).

## 5. Accessibility checklist

- Text contrast is at least 4.5:1 (body) and 3:1 (large text and UI). The semantic tokens are chosen to pass on `surface` and `background`.
- Status is never shown by colour alone. Badges always carry a text label.
- Focus is visible on every interactive element (a global `:focus-visible` outline in `--ring`).
- Respect `prefers-reduced-motion`: celebratory animations must use `motion-safe:`.
- Form errors are linked with `aria-describedby`, and invalid inputs set `aria-invalid`.

## 6. Brand assets

- Transparent PNG logos live in `public/images/brand/`: `logo-tight.png` (the cropped full lockup, used by `AppLogoImage`), `logo-crest.png` (crown and shield, for the collapsed sidebar) and `logo.png` (with margins). `logo-full.jpg` is the original raster.
- **To do:** request the SVG logo and a square crest mark from the client, for the favicon, the app icon and the nav.

## Responsive scale

Every page is mobile first. Sizes step up at Tailwind v4's default breakpoints: `sm` 40rem (640px), `md` 48rem (768px), `lg` 64rem (1024px), `xl` 80rem (1280px) and `2xl` 96rem (1536px). Use these utilities, defined in `resources/css/app.css`, instead of fixed pixel sizes:

| Utility         | Phone   | `sm`    | `lg`    | Use for                                                        |
| --------------- | ------- | ------- | ------- | -------------------------------------------------------------- |
| `p-card`        | 16px    | 20px    | —       | Card padding (`Card`, `CardHeader`, `CardContent`, stat cards) |
| `text-title`    | 20 / 28 | 24 / 32 | 26 / 36 | Page titles (the app header)                                   |
| `text-section`  | 18 / 26 | 20 / 28 | 22 / 32 | Section headings on dashboards and pages                       |
| `text-stat`     | 24 / 32 | 28 / 36 | —       | Big numbers in stat cards                                      |
| `text-body`     | 14 / 22 | 15 / 24 | —       | Body copy and form fields                                      |
| `text-lead`     | 15 / 24 | 17 / 28 | —       | Intro paragraphs                                               |
| `text-headline` | 24 / 32 | 30 / 38 | 36 / 44 | Landing and auth headings                                      |
| `text-display`  | 30 / 38 | 40 / 48 | 46 / 54 | The landing hero only                                          |

Values are font size / line height in pixels. Other rules:

- **Layout:** stat grids are two columns on phones and four from `xl`. Page gaps are 16px, then 20px at `sm`, then 24px at `lg`.
- **Controls:** inputs, selects and large buttons are 40px tall on phones and 44–48px from `sm`.
- **Merging:** `cn()` (tailwind-merge) knows these utilities, so a page-level `p-0` or `text-xs` overrides them cleanly.
