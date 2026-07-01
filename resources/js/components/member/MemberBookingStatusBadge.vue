<script setup lang="ts">
/**
 * MemberBookingStatusBadge — a token-driven booking-status pill for the warm
 * member side. Mirrors MemberStateBadge: the "color + icon + Thai label, never
 * color alone" rule (a11y 1.4.1) — every status pairs a soft token background
 * with a lucide icon AND its Thai label, all in ink text.
 *
 * Status → token (per Phase 7 spec):
 * - confirmed:   info-surface,     calendar-check → "ยืนยันแล้ว"
 * - checked_in:  member-accent,    log-in         → "เช็คอินแล้ว"
 * - completed:   success-surface,  circle-check   → "ใช้บริการแล้ว"
 * - cancelled:   disabled-bg,      x              → "ยกเลิก"
 * - no_show:     warning-surface,  clock-alert    → "ไม่มาตามนัด"
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
    /** Background utility (a soft `*-surface` / accent / disabled token — never white text). */
    bgClass: string;
};

const STYLES: Record<BookingStatus, BadgeStyle> = {
    confirmed: {
        icon: CalendarCheck,
        bgClass: 'bg-[var(--color-info-surface)]',
    },
    checked_in: {
        icon: LogIn,
        bgClass: 'bg-[var(--color-member-accent)]',
    },
    completed: {
        icon: CircleCheck,
        bgClass: 'bg-[var(--color-success-surface)]',
    },
    cancelled: {
        icon: X,
        bgClass: 'bg-[var(--color-disabled-bg)]',
    },
    no_show: {
        icon: ClockAlert,
        bgClass: 'bg-[var(--color-warning-surface)]',
    },
};

const current = computed(() => STYLES[props.status] ?? STYLES.confirmed);
const label = computed(() => bookingStatusLabel(props.status));
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium text-[var(--color-ink)]"
        :class="current.bgClass"
    >
        <component
            :is="current.icon"
            class="size-3.5 shrink-0"
            aria-hidden="true"
        />
        {{ label }}
    </span>
</template>
