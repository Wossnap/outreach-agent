import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

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
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
