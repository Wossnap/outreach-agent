---
category: Actions
---
Sentence-case buttons with a 6px radius. One `primary` (solid navy) per view for the main action; `secondary` (ghost outline) for everything else; `danger` (brick outline) only for destructive actions such as reject, delete, disconnect. Buttons are never uppercase and never carry icons or emoji.

`size="sm"` is for toolbars and table rows.

```tsx
<div className="flex gap-3">
    <Button>Approve</Button>
    <Button variant="secondary">Edit view</Button>
    <Button variant="danger">Reject</Button>
</div>
```
