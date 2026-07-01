import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        // Wayfinder regenerates typed route/action helpers by shelling out to
        // `php artisan wayfinder:generate`. Skip it when SKIP_WAYFINDER is set —
        // e.g. building on managed hosting (Plesk) whose npm-build shell has no
        // `php` on PATH. The generated files under resources/js/{actions,routes}
        // are committed, so the build just compiles them as-is.
        ...(process.env.SKIP_WAYFINDER ? [] : [wayfinder({ formVariants: true })]),
    ],
});
