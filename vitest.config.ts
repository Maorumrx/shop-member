import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

/**
 * Vitest config for the Vue/Inertia front-end.
 *
 * Kept SEPARATE from `vite.config.ts` on purpose: the production build config
 * pulls in the Laravel, Inertia and Wayfinder plugins (the last shells out to
 * `php artisan`), none of which the component test runner needs. Here we load
 * ONLY `@vitejs/plugin-vue` (to compile SFCs) plus the `@/` alias that mirrors
 * tsconfig's `"@/*": ["./resources/js/*"]`.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.{test,spec}.ts'],
        globals: true,
        restoreMocks: true,
    },
});
