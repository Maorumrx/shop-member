<script setup lang="ts">
/**
 * MemberLayout — the warm-soft shell for the LINE LIFF member side.
 *
 * Design dialect (docs/design-system.md): Member = soft / rounded / motion.
 * Warm-white canvas, generous whitespace, rounded-2xl cards, warm-toned soft
 * shadow (`--shadow-card`), ink text. Member pages render their content inside
 * the default slot. Motion is kept gentle and respects prefers-reduced-motion.
 */
import { onMounted, onUnmounted } from 'vue';
import { initializeTheme } from '@/composables/useAppearance';

withDefaults(
    defineProps<{
        /** Optional heading shown above the card (e.g. shop name). */
        title?: string;
        /**
         * Shell layout mode:
         * - `card` (default): centered `max-w-sm`, vertically centered, content
         *   wrapped in a single soft card. Used by Login — unchanged.
         * - `feed`: a wider (`max-w-md`), top-aligned column rendered DIRECTLY on
         *   the warm canvas (no wrapping card). The page supplies its own stack of
         *   cards (the dashboard). Adds bottom breathing room.
         */
        variant?: 'card' | 'feed';
    }>(),
    {
        title: '',
        variant: 'card',
    },
);

// Member side is ALWAYS the warm-white canvas — it must never honor the
// admin/system dark theme (design-system.md), which would paint a near-black
// frame around the warm UI. Strip `.dark` while a member page is mounted, then
// restore the admin appearance preference when leaving.
onMounted(() => {
    document.documentElement.classList.remove('dark');
});

onUnmounted(() => {
    initializeTheme();
});
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center gap-6 bg-[var(--color-bg)] p-6 text-[var(--color-ink)] md:p-10"
        :class="
            variant === 'feed'
                ? 'justify-start pb-10'
                : 'justify-center'
        "
    >
        <main
            class="w-full"
            :class="variant === 'feed' ? 'max-w-md' : 'max-w-sm'"
        >
            <header v-if="title" class="mb-6 text-center">
                <h1
                    class="font-heading text-xl font-semibold text-[var(--color-ink)]"
                >
                    {{ title }}
                </h1>
            </header>

            <!-- Feed: render the page's own card stack directly on the canvas. -->
            <slot v-if="variant === 'feed'" />

            <!-- Card (default, e.g. Login): wrap the slot in one soft card. -->
            <div
                v-else
                class="member-card rounded-2xl bg-[var(--color-surface)] p-8"
                :style="{ boxShadow: 'var(--shadow-card)' }"
            >
                <slot />
            </div>

            <slot name="footer" />
        </main>
    </div>
</template>

<style scoped>
.member-card {
    /* Gentle entrance — soft beauty motion, 150–220ms ease-out per design system. */
    animation: member-card-in 200ms ease-out both;
}

@media (prefers-reduced-motion: reduce) {
    .member-card {
        animation: none;
    }
}

@keyframes member-card-in {
    from {
        opacity: 0;
        transform: translateY(8px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>
