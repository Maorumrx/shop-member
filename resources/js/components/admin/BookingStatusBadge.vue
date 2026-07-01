<script setup lang="ts">
/**
 * BookingStatusBadge (admin) — a token-driven booking-status pill for the dense
 * admin day board. The shadcn `Badge` only offers default/secondary/destructive,
 * which can't express the five booking states distinctly, so this uses the same
 * semantic state tokens as the member side (info/accent/success/disabled/warning
 * surfaces) while keeping the compact admin badge shape.
 *
 * Every status pairs a soft token background with a lucide icon AND its Thai
 * label (a11y 1.4.1 — never color alone), rendered in ink for contrast.
 */
import { CalendarCheck, CircleCheck, ClockAlert, LogIn, X } from '@lucide/vue';
import { computed  } from 'vue';
import type {Component} from 'vue';
import { bookingStatusLabel } from '@/types/bookings';
import type { BookingStatus } from '@/types/bookings';

const props = defineProps<{
    status: BookingStatus;
}>();

type BadgeStyle = {
    icon: Component;
    bgClass: string;
};

const STYLES: Record<BookingStatus, BadgeStyle> = {
    confirmed: {
        icon: CalendarCheck,
        bgClass: 'bg-[var(--color-info-surface)]',
    },
    checked_in: { icon: LogIn, bgClass: 'bg-[var(--color-member-accent)]' },
    completed: {
        icon: CircleCheck,
        bgClass: 'bg-[var(--color-success-surface)]',
    },
    cancelled: { icon: X, bgClass: 'bg-[var(--color-disabled-bg)]' },
    no_show: { icon: ClockAlert, bgClass: 'bg-[var(--color-warning-surface)]' },
};

const current = computed(() => STYLES[props.status] ?? STYLES.confirmed);
const label = computed(() => bookingStatusLabel(props.status));
</script>

<template>
    <span
        class="inline-flex w-fit items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap text-[var(--color-ink)]"
        :class="current.bgClass"
    >
        <component
            :is="current.icon"
            class="size-3 shrink-0"
            aria-hidden="true"
        />
        {{ label }}
    </span>
</template>
