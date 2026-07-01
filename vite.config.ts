import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig(({ command }) => ({
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
        // `php artisan wayfinder:generate`. Run it only in DEV (serve) so route
        // changes regenerate live; SKIP it during `build` so production/CI builds
        // never need `php` on PATH (managed hosting like Plesk runs npm in a shell
        // without php). The generated files under resources/js/{actions,routes}
        // are committed and compiled as-is. Force it in a build with FORCE_WAYFINDER=1
        // (only when php IS available and you want to regenerate).
        ...(command === 'serve' || process.env.FORCE_WAYFINDER
            ? [wayfinder({ formVariants: true })]
            : []),
    ],
}));
