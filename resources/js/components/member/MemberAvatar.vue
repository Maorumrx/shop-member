<script setup lang="ts">
/**
 * MemberAvatar — the member's LINE avatar, or an initials circle when no photo
 * is linked. Reusable across the member surface.
 *
 * a11y: the `<img>` carries a meaningful `alt` = the member's name (not
 * "avatar"); the initials fallback uses the warm accent token with ink text
 * (never white on accent) and is hidden from AT via the wrapper's label.
 */
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        /** Member display name — used for `alt` and the initials fallback. */
        name: string;
        /** LINE avatar URL; null renders the initials circle. */
        avatarUrl?: string | null;
        /** Tailwind size class for the circle (default `size-14`). */
        sizeClass?: string;
    }>(),
    {
        avatarUrl: null,
        sizeClass: 'size-14',
    },
);

/** First glyph of the (trimmed) name; falls back to a neutral dot. */
const initials = computed(() => {
    const trimmed = props.name.trim();
    return trimmed.length > 0 ? Array.from(trimmed)[0] : '•';
});
</script>

<template>
    <img
        v-if="avatarUrl"
        :src="avatarUrl"
        :alt="name"
        :class="sizeClass"
        class="shrink-0 rounded-full object-cover"
    />
    <span
        v-else
        :class="sizeClass"
        class="flex shrink-0 items-center justify-center rounded-full bg-[var(--color-member-accent)] font-heading text-lg font-semibold text-[var(--color-ink)]"
        :aria-label="name"
        role="img"
    >
        {{ initials }}
    </span>
</template>
