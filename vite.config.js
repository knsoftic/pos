import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    /*
     * The supported floor, declared rather than inherited (#180).
     *
     * This is NOT a preference. Tailwind CSS v4 is built on color-mix(),
     * @property and cascade layers, so below these versions the styling does
     * not degrade -- it does not happen. Vite's own default target is lower,
     * which would mean shipping JavaScript to browsers that cannot render the
     * page it drives.
     *
     * Chrome and Edge are the priority: that is what tills run.
     * <x-browser-notice> tells anything older why the screen looks wrong,
     * using @supports rather than a user-agent string.
     */
    build: {
        target: ['chrome111', 'edge111', 'firefox128', 'safari16.4'],
    },

    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
