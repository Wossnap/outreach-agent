---
category: Navigation
---
A row of mutually exclusive choices, such as a date window or a view mode. The selected option is solid navy; the others are muted and lift to sand on hover. Sits on the right of a page title row.

```tsx
<SegmentedToggle
    options={[{ value: '7', label: 'Last 7 days' }, { value: '30', label: 'Last 30 days' }]}
    value={window}
    onChange={setWindow}
/>
```
