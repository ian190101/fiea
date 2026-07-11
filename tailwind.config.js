import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            colors: {
                brand: {
                    primary: 'rgb(var(--brand-primary) / <alpha-value>)',
                    secondary: 'rgb(var(--brand-secondary) / <alpha-value>)',
                    accent: 'rgb(var(--brand-accent) / <alpha-value>)',
                },
                app: {
                    bg: 'rgb(var(--app-bg) / <alpha-value>)',
                    surface: 'rgb(var(--app-surface) / <alpha-value>)',
                    text: 'rgb(var(--app-text) / <alpha-value>)',
                    muted: 'rgb(var(--app-muted) / <alpha-value>)',
                    border: 'rgb(var(--app-border) / <alpha-value>)',
                },
            },
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
