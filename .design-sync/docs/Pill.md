---
category: Status
---
A status pill. `tone` is the meaning, not the colour, and the app never uses green, yellow or red:

- `good` solid navy: ok, valid, healthy, sent, active
- `warn` gold outline: risky, pending, paused
- `danger` brick outline: invalid, bounced, failed, critical
- `neutral` sand fill: unknown, not checked, tags, plain counts

Pills are also the tags in a table cell (neutral, wrapped with `flex flex-wrap gap-1`) and the count badge beside a nav label (`good`).

```tsx
<Pill tone="warn">Risky</Pill>
```
