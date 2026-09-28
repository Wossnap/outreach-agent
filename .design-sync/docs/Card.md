---
category: Layout
---
The only container: white on cream, 10px radius, 1px hairline, never a shadow. Pages are a `max-w-7xl mx-auto space-y-6` column of cards under a page title. `title` adds a sans 16px heading row, `action` puts a link or small button on its right, and `padding="none"` is for a table or list that manages its own rows (use `divide-y divide-rule`).

```tsx
<Card title="Find an address" action={<Button size="sm" variant="secondary">Order by price</Button>}>
    …
</Card>
```
