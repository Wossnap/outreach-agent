---
category: Navigation
---
A top-bar link. The bar is `bg-surface border-b border-rule`, 64px tall, with the `Wordmark` on the left; links are `h-16` so the active navy underline sits on the bar's bottom edge. `badge` shows a count as a navy pill, for things needing attention (pending approvals, unread replies).

```tsx
<nav className="bg-surface border-b border-rule">
    <div className="max-w-7xl mx-auto px-6 flex items-center gap-8 h-16">
        <Wordmark className="text-xl" />
        <NavLink href="/dashboard" className="h-16">Dashboard</NavLink>
        <NavLink href="/approvals" className="h-16" badge={3}>Approvals</NavLink>
        <NavLink href="/leads" className="h-16" active>Leads</NavLink>
    </div>
</nav>
```
