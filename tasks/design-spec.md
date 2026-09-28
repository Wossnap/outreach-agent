# seannocode design system — how it is applied in this app

Source: Claude Design project "SeanNoCode Design System" v2.0 "Trust"
(https://claude.ai/design/p/019decb4-902e-7a30-983e-18ffa7d43c0a). Navy authority on
warm cream, one gold mark per frame, Public Sans body, Source Serif 4 for page titles,
JetBrains Mono for real code only, 10px cards, 6px buttons, no shadows, no gradients.

## Tokens (tailwind.config.js + resources/css/app.css)

Semantic colours are CSS variables that swap when `<html class="dark">`. Views use
ONLY these names and carry NO `dark:` variants at all.

| class stem      | light (cream ground)   | dark (navy ground)          | use for |
| --------------- | ---------------------- | --------------------------- | ------- |
| `page`          | cream #FAF7F1          | navy-deep #0C2136           | page background |
| `surface`       | white                  | navy #14304D                | cards, inputs, nav, dropdown panels |
| `band`          | sand #F0E9DC           | navy-raised #1C405F         | inset panels, table detail rows, hover rows, chips |
| `ink`           | ink #22303C            | navy-ink #F3EEE3            | text |
| `ink-dim`       | #66707B                | #9DAEC0                     | muted text, captions, labels |
| `rule`          | #E6DECD                | #2B4C6D                     | hairlines, card borders, dividers |
| `rule-strong`   | taupe #CFC3AD          | #3E5F80                     | input borders, ghost-button borders |
| `brand`         | navy #14304D           | navy-ink #F3EEE3            | primary button bg, active nav, selected toggle, "good" pills |
| `brand-hover`   | navy-raised            | white                       | primary button hover |
| `brand-ink`     | navy-ink #F3EEE3       | navy-deep #0C2136           | text on `brand` |
| `accent`        | gold-deep #8D6E23      | gold #C29B3B                | THE one gold mark: the wordmark's period. Nothing else. |
| `warn`          | gold-deep              | gold                        | warning status pills / bars |
| `danger`        | alert #A9443C          | #E08A82                     | errors, destructive, "bad" status |

Fixed brand tokens also exist for surfaces that stay navy/gold in both modes:
`cream sand taupe navy navy-deep navy-raised navy-ink navy-ink-dim navy-rule gold gold-deep alert`.
Use them only when something is deliberately navy (e.g. a code block: `bg-navy-deep text-navy-ink`).

Other tokens: `font-sans` (Public Sans), `font-display` (Source Serif 4), `font-mono`
(JetBrains Mono), `rounded-card` (10px), `rounded-md` (6px, buttons/inputs/pills-that-are-square),
`rounded-full` (pills), `tracking-label` (0.08em), `transition` (240ms brand easing by default).

## Class mapping (old Breeze/Tailwind → new)

Drop every `dark:` class. Then:

| old | new |
| --- | --- |
| `bg-gray-100` page bg | `bg-page` (usually the layout already does it — just delete) |
| `bg-white … shadow sm:rounded-lg` card | `bg-surface border border-rule sm:rounded-card` |
| `bg-white` elsewhere | `bg-surface` |
| `bg-gray-50` / `bg-gray-900/40` inset | `bg-band` |
| `hover:bg-gray-50` / `hover:bg-gray-100` | `hover:bg-band` |
| `text-gray-900` / `text-gray-800` / `text-gray-700` | `text-ink` |
| `text-gray-600` / `-500` / `-400` | `text-ink-dim` |
| `border-gray-200` / `-100`, `divide-gray-200` | `border-rule`, `divide-rule` |
| `border-gray-300` (inputs, buttons) | `border-rule-strong` |
| `bg-indigo-600 text-white` (selected/active) | `bg-brand text-brand-ink` |
| `text-indigo-600 hover:underline` (action links) | `font-medium text-brand hover:underline` |
| content links (names, companies, URLs) | `text-ink underline decoration-rule-strong underline-offset-2 hover:decoration-ink` |
| `bg-indigo-100 text-indigo-800` chips/tags | `<x-pill>` (neutral) |
| `bg-indigo-50` / `bg-blue-50 text-blue-800` info banners | `bg-band border border-rule text-ink` |
| `rounded-lg` on cards | `rounded-card` |
| any `shadow*` | delete; floating panels get `border border-rule` instead |
| `focus:ring-indigo-500` | `focus:ring-brand` (inputs) / `focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-page` (buttons) |
| `font-mono` on emails, tags, domains, provider names, dates | delete — plain sans |
| `font-mono` on API keys, commands, JSON/raw payloads, message-ids | keep, and `text-sm` |

## Status colours (green/yellow/red/amber/blue are banned)

Use the pill component: `<x-pill tone="good|warn|danger|neutral">text</x-pill>`.
It renders `data-tone="…"` so tests assert on the tone, not the colour class.

| meaning | tone | looks like |
| --- | --- | --- |
| ok / valid / healthy / sent / active | `good` | solid navy pill, cream text |
| risky / warning / pending / paused | `warn` | gold outline, gold-deep text |
| invalid / bounced / failed / critical / stopped-by-fault | `danger` | brick outline, brick text |
| unknown / not checked / draft / neutral counts | `neutral` | sand fill, ink text |

Bars, dots and text that are not pills: `bg-brand` / `text-ink` for good, `bg-warn` / `text-warn`
for warn, `bg-danger` / `text-danger` for danger, `bg-rule-strong` / `text-ink-dim` for neutral.
Error copy under inputs: `text-danger`. Success flash: `text-ink` in a `bg-band` panel.

## Type

- Page title (`<h2>` at the top of a page): `font-display text-2xl font-semibold tracking-tight text-ink`.
  A count beside it: `text-sm font-sans font-normal text-ink-dim`.
- Card / section title: `text-base font-semibold text-ink` (sans).
- Small labels, table headers, kickers, stat-tile labels: `text-xs font-semibold uppercase tracking-label text-ink-dim`.
- Body: default. Muted body: `text-ink-dim`. Big stat numbers: `text-3xl font-semibold text-ink`.
- No ALL-CAPS buttons: buttons are sentence case, `text-sm font-semibold`.

## Components (already restyled — use them, don't inline their classes)

`<x-primary-button>` navy solid · `<x-secondary-button>` ghost outline · `<x-danger-button>` brick outline
`<x-text-input>` · `<x-input-label>` · `<x-input-error>` · `<x-pill tone>` · `<x-wordmark>`
`<x-dropdown>` / `<x-dropdown-link>` · `<x-modal>` · `<x-nav-link>` · `<x-index.sort-header>` · `<x-index.multi-select>`

Inline buttons that cannot use the components (e.g. `wire:click` toggles in a toolbar) copy the
component's classes: primary `px-4 py-2 rounded-md bg-brand text-brand-ink text-sm font-semibold hover:bg-brand-hover transition`,
ghost `px-4 py-2 rounded-md border border-rule-strong text-ink text-sm font-semibold hover:border-ink-dim transition`.
Small toolbar buttons may use `px-3 py-1.5`. Segmented toggles: container `rounded-md border border-rule bg-surface p-1`,
selected `bg-brand text-brand-ink`, unselected `text-ink-dim hover:bg-band`.

Tables: `<thead>` cells `px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim`;
`<tbody class="divide-y divide-rule">`; rows `hover:bg-band` where rows are clickable/expandable.
Checkboxes: `rounded border-rule-strong text-brand focus:ring-brand`.

## Rules

- No `dark:` classes. No gray/indigo/blue/green/yellow/red/amber/emerald classes. No shadows. No gradients.
- Spacing stays on the 8px scale already used (p-3/4/5/6/8, gap-1/2/3/4/6).
- Do not change any PHP, `wire:` attributes, Alpine logic, text copy, or structure — classes only,
  except where a coloured `<span>` badge becomes `<x-pill tone>`.
- Exactly one gold element per page: the wordmark's period in the nav. Do not add `text-accent` elsewhere.
