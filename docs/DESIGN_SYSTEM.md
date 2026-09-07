# Verapay UI Guidance

Implementation-ready, token-driven UI guidance for the Verapay dashboard, optimized for consistency, accessibility, and fast delivery across the product.

## 1. Context and goals

**Design intent, in one sentence:** Verapay's UI should let a customer or operator understand and act on financial state at a glance, using a small set of reusable, accessible components rather than one-off styling per screen.

- Brand / product: Verapay — Secure & Fast Payments
- Audience: authenticated customers, payment operators, administrators, support staff
- Product surface: web dashboard application (PHP + Tailwind, server-rendered pages with fetch-driven updates)
- Source of truth: `public/assets/css/tokens.css` (tokens) and `public/assets/css/app.css` (component classes). This document explains and constrains that code — if they ever disagree, the code is a bug, not this document.

Every rule below follows one discipline: **must** = non-negotiable, **should** = recommended default a team can deviate from with a documented reason. System consistency beats a locally-nicer one-off every time a conflict comes up.

## 2. Design tokens and foundations

### 2.1 Typography

```
font.family.primary = Montserrat
font.family.stack    = Montserrat, ui-sans-serif, system-ui, sans-serif
font.size.base        = 16px
font.lineHeight.base  = 25.6px (1.6)
```

| Token | Size | Line height | Use for |
|---|---|---|---|
| `text-xs` | 12px | 1.1 | Badge labels, timestamps, table meta |
| `text-sm` | 12.25px | 1.2 | Helper text, secondary labels |
| `text-md` | 13.6px | 1.35 | Body copy, table cells, button labels |
| `text-lg` | 14px | 1.4 | Field input text |
| `text-xl` | 14.4px | 1.4 | Emphasized inline text |
| `text-2xl` | 16px | 1.5 | Card titles |
| `text-3xl` | 20px | 1.4 | Page/section headings, modal titles |
| `text-4xl` | 25.6px | 1.25 | Primary balance figures, hero numbers |

**Must:** every piece of UI text maps to one of these eight tokens. **Must not:** set a one-off `font-size` in a component's markup or inline style — if nothing fits, that's a signal to propose a ninth token, not to freehand a value.

### 2.2 Color

Colors are defined twice in `tokens.css`: as a hex value (for direct CSS/SVG references) and as an `R G B` triple (so Tailwind can compose `color/40`-style opacity). Both must stay in sync.

| Semantic token | Value | Role |
|---|---|---|
| `color.text.primary` | `#344767` | Highest-emphasis text (headings, primary values) — 8.9:1 on white |
| `color.text.secondary` | `#67748e` | Supporting text, labels, helper copy — 4.6:1 on white |
| `color.text.tertiary` | `#1e40af` | Links, accented inline text — 8.6:1 on white |
| `color.text.inverse` | `#f8fafc` | Text on dark surfaces (sidebar, hero banner) |
| `color.surface.base` | `#ffffff` | Page background |
| `color.surface.muted` | `#f8f9fa` | Subtle section backgrounds, hover fills, skeletons |
| `color.surface.raised` | `#ffffff` | Cards, inputs, modals, dropdowns |
| `color.surface.strong` | `#16181d` | Sidebar, inverse/dark panels |
| `color.border.default` | `#e1e1e1` | Decorative dividers only — **must not** carry meaning or delimit an interactive control |
| `color.border.strong` | `#b9bfc7` | Functional borders: inputs, focus-adjacent outlines — passes the 3:1 non-text contrast minimum |
| `color.brand` | `#1e40af` | Primary actions, active nav, focus ring |
| `color.brand.emphasis` | `#14275e` | Hover/active state of brand-colored elements |
| `color.brand.muted` | `#e8f0fe` | Selected/active background tint |
| `color.success` / `.bg` | `#15803d` / `#ecfdf3` | Success state, text on `.bg` |
| `color.danger` / `.bg` | `#b91c1c` / `#fef2f2` | Errors, destructive actions |
| `color.warning` / `.bg` | `#b45309` / `#fffbeb` | Pending/attention states |
| `color.info` / `.bg` | `#0e7490` / `#ecfeff` | Informational states (refunded, neutral highlight) — cyan, kept distinct from brand blue |
| `color.neutral` / `.bg` | `#475569` / `#f1f5f9` | Cancelled/inactive states |

**Must:** reference colors through their Tailwind class (`text-text-secondary`, `bg-brand`, `border-border-strong`, …), never a raw hex value in markup or a component's CSS. **Must not:** use color as the only signal for status — pair every status color with an icon or text label (see §4, status components).

Two supplied palette values were replaced rather than shipped as-is, because they failed accessibility for their intended use:

- Inverse text: the originally supplied tone was a mid-gray, unreadable on the dark sidebar/hero surfaces it's meant for. Replaced with a near-white (`#f8fafc`).
- Functional borders: the originally supplied border tone measured ~1.2:1 against white, far under the 3:1 WCAG 2.2 non-text contrast minimum for input/focus boundaries. Replaced with `#b9bfc7` (~3:1) and reserved the original lighter tone for decorative dividers only.

### 2.3 Spacing

```
space.1 = 4px    space.5 = 10px
space.2 = 5px    space.6 = 12px
space.3 = 6px    space.7 = 15px
space.4 = 8px    space.8 = 16px
```

**Should:** compose layouts from Tailwind's standard spacing scale (which these values map onto — `p-1`…`p-4`, `gap-1.5`, etc.). **Must not:** introduce an arbitrary spacing value (e.g. `pt-[13px]`) outside a documented, technically-necessary exception (e.g. optically balancing an icon's baseline).

### 2.4 Radius

| Token | Value | Use for |
|---|---|---|
| `radius.xs` | 6px | Inputs, small buttons, icon buttons |
| `radius.sm` (Tailwind `rounded`) | 8px | Default buttons |
| `radius.md` | 12px | Cards, modals, dropdown panels |
| `radius.lg` | 14px | Reserved for larger surfaces |
| `radius.xl` | 15px | Reserved for larger surfaces |
| `radius.full` | 50px | Pills/badges, avatar circles |

**Must not:** apply `rounded-full` to a rectangular content container (cards, modals) — reserve fully-rounded corners for pill/circle shapes only, per the "precise, not soft" brand direction.

### 2.5 Shadow & motion

```
shadow.card = rgba(149, 157, 165, 0.2) 0px 8px 24px 0px   /* elevated: modals, toasts, hover-lifted cards */
shadow.soft = rgba(30, 64, 175, 0.05) 0px 4px 12px 0px    /* resting: default card elevation */

motion.duration.instant = 200ms   /* hover, focus, dropdown open/close, button loading */
motion.duration.fast    = 300ms   /* sidebar drawer, modal open/close */
motion.easing            = cubic-bezier(0.4, 0, 0.2, 1)
```

**Must:** every transition respects `prefers-reduced-motion` — both duration tokens collapse to `0ms` under that media query (already wired in `tokens.css`); component authors must not hardcode a duration that bypasses it. **Must not:** add a decorative animation (parallax, bounce, auto-playing motion) that doesn't communicate a state change.

## 3. Component-level rules

Each component below lists anatomy, variants, every required interaction state, responsive behavior, and edge-case handling. A component ships only when every applicable state in this list is implemented — see the QA checklist (§6).

### 3.1 Button

**Anatomy:** optional leading icon, label, optional trailing icon. Fixed height per size; label never wraps.

**Variants:** `primary` (one per view — the single most important action), `secondary`, `tertiary` (low-emphasis, text-only feel), `danger` (destructive actions only), `ghost` (chromeless, for dense toolbars), `icon` (square, icon-only — **must** carry `aria-label`).

**States (all variants must define):**
| State | Rule |
|---|---|
| Default | Token background/text per variant above |
| Hover | Background shifts to the variant's emphasis/muted tone; 200ms transition |
| Focus-visible | 2px `color.brand` outline, 2px offset (global, not variant-specific) |
| Active | Same visual as hover (no separate "pressed" treatment) |
| Disabled | 50% opacity, `cursor: not-allowed`, no hover/active response |
| Loading | Label replaced by a centered spinner *at the button's existing dimensions* — width/height must not shift; the button becomes non-interactive (`pointer-events: none`) so a slow network can't produce duplicate submissions |
| Error | Not a button-level state — surface the error via the adjacent `field-error` or a toast; the button itself just leaves loading and re-enables |

**Keyboard/pointer/touch:** `Tab`/`Shift+Tab` to reach it, `Enter` or `Space` to activate. Minimum touch target 36×36px (icon buttons are exactly `w-9 h-9` = 36px). Pointer and touch share the same activation — no hover-dependent functionality.

**Responsive/edge cases:** a full-width button (`w-full`) on mobile forms must still cap its label at one line — truncate with an accessible full label via `title`/`aria-label` if a translation could overflow. A row of buttons wraps to a new line before it scrolls horizontally.

### 3.2 Card

**Anatomy:** optional icon, title (`card-title`), optional subtitle (`card-subtitle`), body content, optional footer action/link.

**Variants:** static (informational — balance summaries, form containers) and `card-interactive` (the whole card is a link/action — **must** actually navigate or perform an action; **must not** apply the hover-lift purely for visual flourish on a non-clickable card).

**States:**
| State | Rule |
|---|---|
| Default | `surface.raised` background, `border.default` hairline, `shadow.soft`, `radius.md` |
| Hover (interactive only) | `shadow.card`, lifts 2px (`-translate-y-0.5`), border fades — signals "this leads somewhere" |
| Focus-visible (interactive only) | Standard focus ring, since the whole card is the interactive element |
| Loading | Internal skeleton blocks (§3.7) replace real content; card chrome (border/shadow/padding) stays stable so layout doesn't jump |
| Empty | See §4 empty-state content rules — never render an empty card with just a heading and blank space |
| Error | Inline retry affordance inside the card body, not a separate error card |

**Responsive:** cards reflow in a CSS grid (`grid-cols-1` → `sm:grid-cols-2` → `lg:grid-cols-4`, per page); a card never gets so narrow that its title truncates before its value does — value takes truncation priority over label if both must yield.

**Density target:** informational stat cards (KPI/report cards) should stay ≤ 10 per page; a page needing more should group them under a report heading (as the Deposits/Withdrawals reports do) rather than one flat grid. The densest current page (Dashboard, ~13 cards split across a balance panel + two 4-card report groups) is the practical ceiling — new pages should stay under it, not match it.

### 3.3 Form field (label + input + help/error)

**Anatomy:** explicit `<label>` (never a placeholder standing in for one), input, optional helper text below, error text below (replaces helper text when present, doesn't stack with it).

**States:**
| State | Rule |
|---|---|
| Default | `border.strong`, `radius.xs`, `text.lg` input text |
| Hover | No visual change (inputs don't have a hover state distinct from focus) |
| Focus-visible | Border becomes `color.brand`; global focus ring also applies |
| Active (typing) | Same as focus |
| Disabled | `surface.muted` background, `text.secondary` text, no focus ring reachable |
| Error (`aria-invalid="true"`) | Border becomes `color.danger`; `field-error` text appears, referenced via `aria-describedby` so assistive tech announces it |
| Loading | N/A at the field level — the *submit* button carries the loading state, not individual fields |

**Keyboard/pointer/touch:** standard text-input behavior; `Tab` order follows visual/DOM order; radio/checkbox groups use arrow-key navigation natively via `<fieldset>`/`<input type="radio">` grouping (see the deposit method selector).

**Content rule:** helper text states the constraint before the user can violate it ("Minimum deposit is ₹10.00"), not after. Error text states what's wrong *and* how to fix it ("Enter an amount of at least ₹10.00" — not "Invalid amount").

**Edge cases:** a value near the field's max width (long email, long reference number) must not break the field's row layout — inputs are always `w-full` inside their own column, never sized to content. Numeric/currency fields validate server-side regardless of any client-side pattern (see `includes/money.php`) — client validation is a UX convenience, never the source of truth.

### 3.4 Badge / status indicator

**Anatomy:** optional leading dot, label text. Pill-shaped (`radius.full`).

**Variants:** `success`, `danger`, `warning`, `info`, `neutral` — mapped 1:1 to the transaction/account status vocabulary (`success`, `failed`, `pending`, `refunded`, `cancelled`/`suspended`).

**Must:** every status badge pairs its color with a text label (`Success`, `Pending`, …) — never a bare colored dot. This is the component that makes the "don't rely on color alone" accessibility rule concrete. **Must not:** invent a new status color without adding it to §2.2 first.

**Edge cases:** unknown/unmapped status strings fall back to `badge-neutral` rather than rendering unstyled text (see `status_badge_class()` in `includes/functions.php`).

### 3.5 Navigation (sidebar)

**Anatomy:** brand mark, primary nav-link group (role-dependent), secondary group (Profile/Settings/Log out), active-page indicator (a 4px brand-colored bar).

**States:**
| State | Rule |
|---|---|
| Default | `text.inverse` at 70% opacity on `surface.strong` |
| Hover | Background gains a faint white wash (`bg-white/5`), text goes to full opacity |
| Focus-visible | Standard focus ring |
| Active (`aria-current="page"`) | Full-opacity text, `bg-white/10`, indicator bar visible |
| Disabled | Not used in navigation — a role that can't reach a page simply doesn't see that nav item, rather than showing it disabled |
| Loading | Not applicable — navigation is a same-origin link, not an async action |

**Keyboard:** full nav is a `<nav>` landmark reachable by `Tab`; each link is a real `<a href>` (works with `Enter`, opens correctly with middle-click/`Cmd+Click`).

**Responsive:** collapses to an off-canvas drawer under `lg` (`1024px`), triggered by a labeled hamburger button in the top bar, closes on `Escape`, backdrop click, or link selection. **Must:** trap nothing — the drawer is a simple transform, not a modal; background content must not be reachable while open only if the backdrop also blocks pointer events (it does — `fixed inset-0`).

**Density:** exactly one primary navigation region per page. A secondary/contextual action row (e.g., transaction filters) is not navigation and must not be styled with `nav-link`.

### 3.6 Table

**Anatomy:** header row (`<th scope="col">` per column — never a bare `<td>` as a header), body rows, optional footer/pagination bar.

**States:** row hover (`surface.muted` fill) is the only row-level state; no row-level focus state beyond whatever interactive element (link/button) a cell contains.

**Responsive/overflow:** wrapped in a horizontally-scrolling container (`overflow-x-auto`) rather than force-fitting all columns at every width — chosen over a stacked-card transform for transaction tables specifically because the data is naturally tabular (many comparable numeric columns) and a card layout would repeat every column label per row, which is worse for scanning than a horizontal scroll.

**Edge cases:** a long reference/ID gets `font-mono` and never wraps mid-token; a long customer name/email truncates with `truncate` rather than reflowing the row taller. Empty result set renders a full-width message row (see §4), never zero `<tr>` elements with no explanation.

### 3.7 Skeleton (loading state)

**Anatomy:** a shape matching the real content's approximate size (text-line height, or the card's real dimensions), with a shimmer sweep.

**Must:** every data-driven view has a skeleton for its first paint — a blank white area while `fetch()` resolves is never acceptable. **Must not:** show a skeleton for a fixed/artificial delay; it dismisses exactly when real data arrives, no sooner or later than that.

**Reduced motion:** shimmer animation is disabled under `prefers-reduced-motion`; the skeleton shape (a static muted block) still communicates "loading" without the motion.

### 3.8 Toast

**Anatomy:** icon (matches type), message, dismiss button. Stacks in a fixed bottom-right region.

**Variants/states:** `success`, `error`, `warning`, `info` — border tint only (see §2.2 pairing rule: type is also carried by the icon and by `role="alert"` vs `role="status"`, not color alone).

**Timing:** success/info/warning auto-dismiss after 5s; **error toasts do not auto-dismiss** — they require manual dismissal, since a critical failure a user didn't get to read is worse than one that lingers. Manual dismiss is always available regardless of type.

**Must:** announce via `aria-live` (the toast region is `aria-live="polite"`; individual error toasts additionally carry `role="alert"` for assertive announcement).

### 3.9 Modal (dialog)

**Anatomy:** title, explanation/body, primary action, secondary/cancel action, explicit close control. Built on native `<dialog>`.

**States:** open (native `::backdrop` dims the page), closing (no exit animation currently defined — instant via `dialog.close()`).

**Keyboard:** `Escape` closes and returns focus to the element that opened it (tracked via `dialog._returnFocus`); focus moves to the first focusable element on open; native `<dialog>` provides the focus trap (focus can't leave the dialog while open) without extra JS.

**Must:** every destructive/irreversible action (suspend user, delete gateway) confirms through this component with a specific consequence sentence ("This customer will be signed out immediately and unable to access Verapay until reactivated") — never a bare "Are you sure?". **Must not:** use a modal for a multi-step flow that belongs on its own page — modals are for one focused decision, not a wizard.

## 4. Accessibility requirements and acceptance criteria

Target: **WCAG 2.2 AA**, testable per rule below.

| Rule | Pass/fail check |
|---|---|
| Text contrast | Every `color.text.*` / `color.surface.*` pairing in real use measures ≥ 4.5:1 (body text) or ≥ 3:1 (large text ≥ 24px/19px bold) — verify with a contrast checker against the actual token pairing, not the raw supplied palette |
| Non-text contrast | Functional borders (inputs, focus rings) measure ≥ 3:1 against their adjacent surface |
| Focus visibility | Tab through every interactive element on a page; each must show the 2px brand-colored ring with no element skipped or losing outline via CSS |
| Keyboard-only operation | Every user-facing flow (login, deposit, withdrawal, support chat, admin suspend) is completable with only `Tab`/`Shift+Tab`/`Enter`/`Space`/`Escape`/arrow keys — no mouse |
| No color-only meaning | For every status badge/indicator, cover the color (e.g. grayscale filter) and confirm the meaning is still readable from text/icon alone |
| Accessible names | Every icon-only control (icon buttons, close buttons) has a non-empty `aria-label`; run an accessibility tree inspector and confirm no "unnamed button" |
| Form errors announced | Trigger a validation error; confirm the error text is programmatically associated via `aria-describedby` and `aria-invalid="true"` is set on the field |
| Dialog accessible name | Every `<dialog>` has `aria-labelledby` pointing to its visible title |
| Live regions | Toasts and polling-updated regions (support messages, notification panel) use `aria-live`; confirm a screen reader announces new content without requiring focus to move there |
| 200% zoom | Reflow every page at 200% browser zoom with no horizontal scroll on the page itself (component-level horizontal scroll, e.g. wide tables, is acceptable) and no clipped/overlapping content |
| Reduced motion | With `prefers-reduced-motion: reduce` set, confirm shimmer/transition durations collapse to 0ms and no non-essential animation plays |

## 5. Content and tone standards

Tone: concise, confident, implementation-focused. No marketing language, no unnecessary exclamation marks.

| Situation | Don't | Do |
|---|---|---|
| Success confirmation | "Your withdrawal request has been successfully submitted and is now being processed by our financial operations system!" | "Withdrawal submitted." |
| Failure | "Oops! Something went wrong." | "Payment failed." + a specific next step if one exists |
| Empty state | (a blank card with just a heading) | "No transactions yet." / "Completed payments will appear here once your first transaction is processed." + a "Make a deposit" action |
| Helper text | "Enter amount" (as the only label) | Explicit label "Amount", helper text "Minimum withdrawal is ₹20.00." |
| Link label | "Click here" | "View all transactions" |
| Destructive confirmation | "Are you sure?" | "This customer will be signed out immediately and unable to access Verapay until reactivated." |
| Error detail | Raw exception / SQL error / stack trace | A plain-language cause, with the technical detail only in server logs (`error_log()`), never in the response body |

**Must not:** expose PHP errors, SQL fragments, file paths, or stack traces in any user-facing surface — `APP_DEBUG=false` in production suppresses PHP's own error output, and every API endpoint's `catch` block returns a generic message while logging the real one server-side.

## 6. Anti-patterns and prohibited implementations

- **Must not** hardcode a hex color, arbitrary spacing value, or one-off font size in a page template — every value traces back to a token in §2.
- **Must not** build a new button/card/badge visual treatment for a single page — extend the shared component classes in `app.css`, or propose a new variant for the whole system.
- **Must not** apply `card-interactive`'s hover lift to a card that doesn't actually navigate/act — hover must always predict a real outcome.
- **Must not** ship a data-driven view without a loading skeleton, an empty state, and an error-with-retry state — all three are part of "the component," not optional polish.
- **Must not** rely on `outline: none` without substituting an equivalent focus-visible style — the global focus ring in `app.css` already exists; don't override it away locally.
- **Must not** use a modal for anything other than one focused decision; must not use a toast for anything that requires the user to take an action (that belongs in the page or a modal).
- **Must not** introduce a second icon set, stroke weight, or emoji alongside the existing 1.75px-stroke inline SVG set in `public/assets/icons/icons.php`.
- **Must not** trust client-submitted amounts, statuses, or IDs — every financial or authorization decision is re-validated server-side regardless of what the UI already prevented.

## 7. QA checklist

Before a component or page ships:

- [ ] Every color used resolves to a token in §2.2 (no raw hex)
- [ ] Every spacing/radius/shadow value resolves to a token in §2.3–2.5
- [ ] Component defines and visually verifies: default, hover, focus-visible, active, disabled, loading, error (where applicable — see per-component tables in §3)
- [ ] Icon-only controls have `aria-label`
- [ ] Status is never conveyed by color alone
- [ ] Keyboard-only pass completed for the flow (Tab order, activation, Escape where relevant)
- [ ] Loading skeleton, empty state, and error-with-retry all implemented for data-driven views
- [ ] Long content (names, references, amounts) tested for truncation/wrap without breaking layout
- [ ] Verified at 1440px, 1024px, 768px, 480px, 360px
- [ ] `prefers-reduced-motion` respected
- [ ] Contrast checked for any new text/background pairing
- [ ] No PHP/SQL error detail reachable in any response body
- [ ] Copy reviewed against §5 (no "click here," no unexplained jargon, error text says what to do next)
