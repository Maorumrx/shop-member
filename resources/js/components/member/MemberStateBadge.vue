<script setup lang="ts">
/**
 * MemberStateBadge — a token-driven status pill. Centralizes the "color + icon +
 * Thai label, never color alone" rule (a11y 1.4.1): every state pairs a
 * `*-surface` background with a lucide icon AND a Thai label, all in ink text.
 *
 * States:
 * - near-expiry:   warning-surface, clock          → "ใกล้หมดอายุ"
 * - never-expires: success-surface, infinity        → "ไม่มีวันหมดอายุ"
 * - used-up:       disabled-bg (muted), check       → "ใช้ครบแล้ว"
 * - addon:         member-accent, sparkles          → "เสริม"
 */
import { CircleCheck, Clock, Infinity, Sparkles } from '@lucide/vue';
import { computed, type Component } from 'vue';

const props = defineProps<{
    state: 'near-expiry' | 'never-expires' | 'used-up' | 'addon';
}>();

type BadgeStyle = {
    label: string;
    icon: Component;
    /** Background utility (a `*-surface` / accent / disabled token, never white text). */
    bgClass: string;
};

const STYLES: Record<typeof props.state, BadgeStyle> = {
    'near-expiry': {
        label: 'ใกล้หมดอายุ',
        icon: Clock,
        bgClass: 'bg-[var(--color-warning-surface)]',
    },
    'never-expires': {
        label: 'ไม่มีวันหมดอายุ',
        icon: Infinity,
        bgClass: 'bg-[var(--color-success-surface)]',
    },
    'used-up': {
        label: 'ใช้ครบแล้ว',
        icon: CircleCheck,
        bgClass: 'bg-[var(--color-disabled-bg)]',
    },
    addon: {
        label: 'เสริม',
        icon: Sparkles,
        bgClass: 'bg-[var(--color-member-accent)]',
    },
};

const current = computed(() => STYLES[props.state]);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium text-[var(--color-ink)]"
        :class="current.bgClass"
    >
        <component :is="current.icon" class="size-3.5 shrink-0" aria-hidden="true" />
        {{ current.label }}
    </span>
</template>
