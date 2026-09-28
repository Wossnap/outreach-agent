import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/*
 * The seannocode design system, v2.0 "Trust": navy authority on warm cream,
 * one gold mark per frame. tasks/design-spec.md says how it is applied.
 *
 * Two kinds of colour. The brand tokens (cream, navy, gold…) are fixed, for
 * things that are deliberately navy or gold whatever the theme. The semantic
 * ones (page, surface, ink…) are CSS variables set in app.css that swap from
 * the cream ground to the navy ground when <html> carries the dark class.
 * Views use the semantic names and carry no dark: variants at all.
 */
const semantic = (name) => `rgb(var(--c-${name}) / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
export default {
    /*
     * Dark mode follows a class on <html>, not the operating system directly.
     *
     * The system setting is still the default: a small script in the layout
     * reads the saved choice, falls back to the system, and sets the class
     * before the page paints. Going through a class is what makes an explicit
     * Light or Dark choice possible at all, since a media query cannot be
     * overridden from the page.
     */
    darkMode: 'selector',

    content: [
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            colors: {
                cream: '#FAF7F1',
                sand: '#F0E9DC',
                taupe: '#CFC3AD',
                navy: {
                    DEFAULT: '#14304D',
                    deep: '#0C2136',
                    raised: '#1C405F',
                    ink: '#F3EEE3',
                    'ink-dim': '#9DAEC0',
                    rule: '#2B4C6D',
                },
                gold: {
                    DEFAULT: '#C29B3B',
                    deep: '#8D6E23',
                },
                alert: '#A9443C',

                page: semantic('page'),
                surface: semantic('surface'),
                band: semantic('band'),
                ink: {
                    DEFAULT: semantic('ink'),
                    dim: semantic('ink-dim'),
                },
                rule: {
                    DEFAULT: semantic('rule'),
                    strong: semantic('rule-strong'),
                },
                brand: {
                    DEFAULT: semantic('brand'),
                    hover: semantic('brand-hover'),
                    ink: semantic('brand-ink'),
                },
                accent: semantic('accent'),
                warn: semantic('warn'),
                danger: semantic('danger'),
            },
            fontFamily: {
                sans: ['"Public Sans"', ...defaultTheme.fontFamily.sans],
                display: ['"Source Serif 4"', 'Georgia', ...defaultTheme.fontFamily.serif],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
            },
            borderRadius: {
                card: '10px',
            },
            letterSpacing: {
                label: '0.08em',
            },
            transitionDuration: {
                DEFAULT: '240ms',
            },
            transitionTimingFunction: {
                DEFAULT: 'cubic-bezier(0.2, 0.8, 0.2, 1)',
            },
        },
    },

    plugins: [forms],
};
