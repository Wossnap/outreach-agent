---
category: Forms
---
Any `<input>` type on a white surface with a taupe border and a navy focus ring. Put an `InputLabel` above it and give the input `mt-1 w-full`. Placeholders are muted ink. Validation messages go in an `InputError` underneath.

```tsx
<div>
    <InputLabel htmlFor="email">Email</InputLabel>
    <TextInput id="email" type="email" className="mt-1 w-full" placeholder="Type to search…" />
</div>
```
