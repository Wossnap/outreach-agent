# design-sync notes

- This is a Laravel/Livewire app whose real components are Blade files in `resources/views/components/`. The synced package at `design-system/` is a hand-written React mirror of them (same markup, same classes) so the claude.ai/design agent has something to render. When a Blade component changes, change its twin in `design-system/src/` too.
- `design-system/tailwind.config.js` wraps the app's Tailwind config and adds `design-system/src/**` and `.design-sync/previews/**` to the content scan, so the shipped `dist/styles.css` carries every class the mirrors and previews use. The app's own build never scans these paths.
- Build: `npm --prefix design-system run build` (tsc for `.d.ts`, esbuild for the ESM entry, the root `node_modules/.bin/tailwindcss` for the stylesheet, then `src/fonts.css` is prepended so fonts load from Google Fonts). Run `npm --prefix design-system install` on a fresh clone first.
- Converter flags: `--node-modules design-system/node_modules --entry ./design-system/dist/index.js`.
- Fonts are remote (`[FONT_REMOTE]`, Google Fonts import in `styles.css`) by design, matching `resources/views/partials/fonts.blade.php`.
- Playwright: the machine's chromium cache is build 1223, which pins `playwright@1.60.0`; that is what `.ds-sync/` installs.
- Docs live in `.design-sync/docs/<Name>.md`; their `category` frontmatter sets the group (Brand, Actions, Status, Forms, Layout, Navigation).
- Known render warns: none. The five `[GRID_OVERFLOW]` warns on Banner, Card, NavLink, StatTile and TextInput are resolved by `cardMode: column` overrides in config.

## Re-sync risks

- Drift between Blade and React: nothing enforces that `design-system/src/*.tsx` matches `resources/views/components/*.blade.php`. Diff the two after any restyle before syncing.
- The token names and the class vocabulary in `.design-sync/conventions.md` come from `tailwind.config.js` and `resources/css/app.css`; a token rename there must be reflected in the header.
- Preview content is realistic but invented (names, counts); it needs no upkeep.
