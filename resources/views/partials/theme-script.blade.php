{{--
    Applies the chosen theme, and keeps it applied.

    Inline and in the <head> on purpose: anything later, including the bundled
    JavaScript, runs after the first paint, so the page would flash before
    turning dark.

    Two things are written. localStorage holds the choice itself, which may be
    "auto". A plain cookie holds what that choice resolved to right now, so the
    layout can put the class on <html> server-side and the very first paint of
    every page is already correct.

    The cookie matters more than it looks. wire:navigate fetches the next page
    and copies its <html> attributes over the current ones, dropping any it does
    not have; without the class being rendered server-side, every navigation
    would strip it and the page would revert to light.
--}}
<script>
    (function () {
        var read = function () {
            try {
                return localStorage.getItem('theme');
            } catch (e) {
                // Private browsing can refuse localStorage. Treat it as auto.
                return null;
            }
        };

        var query = window.matchMedia('(prefers-color-scheme: dark)');

        var apply = function () {
            var choice = read();
            var dark = choice === 'dark' || (choice !== 'light' && query.matches);

            document.documentElement.classList.toggle('dark', dark);

            // A year, on every path, so the server renders the same thing the
            // browser just decided.
            document.cookie = 'theme_resolved=' + (dark ? 'dark' : 'light')
                + ';path=/;max-age=31536000;samesite=lax';
        };

        apply();

        // Someone on Auto who changes their system setting sees it at once.
        query.addEventListener('change', apply);

        // Belt and braces for a first visit, before the cookie exists.
        document.addEventListener('livewire:navigated', apply);

        window.applyTheme = apply;
    })();
</script>
