# Building with the Outreach Agent design system

Navy authority on warm cream. One gold mark per page. Sentence case everywhere. No shadows, no gradients, no icons, no emoji, no green/yellow/red.

## Setup

No provider or wrapper is needed. Every component is a plain styled element; the stylesheet (`styles.css`, which imports `_ds_bundle.css`) carries the tokens and the fonts (Public Sans, Source Serif 4, JetBrains Mono, loaded from Google Fonts). The page body should be `bg-page text-ink font-sans antialiased`. Dark mode is `class="dark"` on `<html>`: every semantic token swaps to the navy ground automatically, so never write `dark:` variants.

## Styling idiom: semantic Tailwind utilities

Layout glue is Tailwind. Colours are ONLY these semantic names (each is a CSS variable, light and dark):

| family | classes | use |
| --- | --- | --- |
| page ground | `bg-page` | the body |
| surfaces | `bg-surface` (cards, inputs, nav), `bg-band` (inset panels, hover rows, chips) | |
| text | `text-ink`, `text-ink-dim` (captions, labels) | |
| lines | `border-rule`, `divide-rule` (hairlines), `border-rule-strong` (inputs, ghost buttons) | |
| brand | `bg-brand text-brand-ink` (selected, primary), `hover:bg-brand-hover`, `text-brand` (action links), `border-brand`, `ring-brand` | |
| status | `text-warn border-warn bg-warn`, `text-danger border-danger bg-danger` | only for meaning, never decoration |
| the one gold mark | `text-accent` | the Wordmark's period already uses it; do not add another |

Fixed brand tokens for surfaces that stay navy in both modes: `bg-navy bg-navy-deep bg-navy-raised text-navy-ink text-navy-ink-dim border-navy-rule`, e.g. a code block is `bg-navy-deep text-navy-ink font-mono text-xs rounded-md p-3`.

Type: page title `font-display text-2xl font-semibold tracking-tight text-ink`; card title `text-base font-semibold text-ink`; small labels, table headers and stat captions `text-xs font-semibold uppercase tracking-label text-ink-dim`; body is default; `font-mono` only for real code, keys, ids and commands.

Shape and motion: cards `rounded-card` (10px), buttons/inputs `rounded-md` (6px), pills `rounded-full`, `transition` is 240ms with the brand easing by default. Spacing on the 8px scale: `p-3 p-4 p-5 p-6 p-8`, `gap-1 gap-2 gap-3 gap-4 gap-6`, `space-y-6` between cards.

Status colours are never chosen by hue: use `<Pill tone="good|warn|danger|neutral">` for badges and `bg-brand` / `bg-warn` / `bg-danger` / `bg-rule-strong` for bars.

## Where the truth lives

Read `styles.css` and `_ds_bundle.css` for every token and utility, and each `components/<group>/<Name>/<Name>.prompt.md` for how that component is used in the app.

## Page skeleton

```tsx
<div className="min-h-screen bg-page">
  <nav className="bg-surface border-b border-rule">
    <div className="max-w-7xl mx-auto px-6 flex items-center gap-8 h-16">
      <Wordmark className="text-xl" />
      <NavLink href="/approvals" className="h-16" badge={3}>Approvals</NavLink>
      <NavLink href="/leads" className="h-16" active>Leads</NavLink>
    </div>
  </nav>
  <main className="max-w-7xl mx-auto px-6 py-12 space-y-6">
    <div className="flex items-center justify-between">
      <h2 className="font-display text-2xl font-semibold tracking-tight text-ink">Leads <span className="text-sm font-sans font-normal text-ink-dim">6</span></h2>
      <Button size="sm" variant="secondary">Filters</Button>
    </div>
    <div className="grid grid-cols-3 gap-4">
      <StatTile label="Sent" value="197" sub="emails delivered to Gmail" />
    </div>
    <Card title="Leads" padding="none">
      <table className="min-w-full divide-y divide-rule text-sm">
        <thead><tr><th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Email</th></tr></thead>
        <tbody className="divide-y divide-rule text-ink"><tr className="hover:bg-band transition"><td className="px-4 py-3">priya@example.com <Pill tone="good">Valid</Pill></td></tr></tbody>
      </table>
    </Card>
  </main>
</div>
```
