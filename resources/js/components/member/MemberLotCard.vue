<script setup lang="ts">
/**
 * MemberLotCard — one credit lot (a top-up or an owner adjustment). Header shows
 * where the credit came from + the purchased date; the body shows the total
 * remaining in baht with a thin fill bar and the เงินสด / โบนัส split.
 *
 * State cues (a11y — color always paired with icon + label via MemberStateBadge):
 * - is_near_expiry → a "near-expiry" badge PLUS a slim WARNING left accent bar.
 * Expiry is shown ONLY when set (`expires_at !== null`); the wallet ships lots
 * with no expiry by default, so most lots show no expiry line at all.
 *
 * Only ACTIVE lots reach here (backend filter). Motion: the fill bar animates
 * 0 → target on mount; reduced-motion snaps to the final width.
 */
import { computed } from 'vue';
import MemberStateBadge from '@/components/member/MemberStateBadge.vue';
import { formatBaht } from '@/lib/money';
import { formatThaiDate } from '@/lib/thai';
import type { CreditSource, WalletLot } from '@/types/members';

const props = defineProps<{
    lot: WalletLot;
}>();

const SOURCE_LABEL: Record<CreditSource, string> = {
    topup: 'เติมเครดิต',
    adjustment: 'เครดิตพิเศษ',
};

const sourceLabel = computed(
    () => SOURCE_LABEL[props.lot.source] ?? 'เครดิต',
);

/** Fill ratio (0–1) of total remaining vs the lot's original total. */
const fillRatio = computed<number>(() => {
    const original =
        Number.parseFloat(props.lot.amount_paid) +
        Number.parseFloat(props.lot.bonus_amount);

    if (original <= 0) {
        return 0;
    }

    const remaining = Number.parseFloat(props.lot.total_remaining);

    return Math.min(Math.max(remaining / original, 0), 1);
});
</script>

<template>
    <article
        class="relative overflow-hidden rounded-2xl border border-[var(--color-member-border)] bg-[var(--color-surface)] p-5 shadow-[var(--shadow-soft)]"
    >
        <!-- Near-expiry: slim WARNING left accent bar (not a full red flood). -->
        <span
            v-if="lot.is_near_expiry"
            class="absolute inset-y-0 left-0 w-1 bg-[var(--color-warning)]"
            aria-hidden="true"
        />

        <!-- Header: source + purchased date + optional near-expiry badge. -->
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="flex flex-col gap-1">
                <h3
                    class="font-heading text-base font-semibold text-[var(--color-ink)]"
                >
                    {{ sourceLabel }}
                </h3>
                <p class="text-xs text-[var(--color-ink-muted)]">
                    เติมเมื่อ {{ formatThaiDate(lot.purchased_at) }}
                    <template v-if="lot.expires_at">
                        · หมดอายุ {{ formatThaiDate(lot.expires_at) }}
                    </template>
                </p>
            </div>

            <MemberStateBadge v-if="lot.is_near_expiry" state="near-expiry" />
        </div>

        <!-- Total remaining + fill bar. -->
        <div class="mt-4 flex items-end justify-between gap-3">
            <p
                class="font-heading text-2xl font-semibold text-[var(--color-ink)] tabular-nums"
            >
                {{ formatBaht(lot.total_remaining) }}
            </p>
            <span class="text-xs text-[var(--color-ink-muted)]">คงเหลือ</span>
        </div>
        <div
            class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-[var(--color-member-border)]"
        >
            <span
                class="member-bar block h-full rounded-full bg-[var(--color-member-accent)]"
                :style="{ '--fill': `${fillRatio * 100}%` }"
            />
        </div>

        <!-- เงินสด / โบนัส split. -->
        <div
            class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-[var(--color-ink-muted)]"
        >
            <span>
                เงินสด
                <span class="font-medium text-[var(--color-ink)] tabular-nums">
                    {{ formatBaht(lot.paid_remaining) }}
                </span>
            </span>
            <span>
                โบนัส
                <span class="font-medium text-[var(--color-ink)] tabular-nums">
                    {{ formatBaht(lot.bonus_remaining) }}
                </span>
            </span>
        </div>
    </article>
</template>

<style scoped>
.member-bar {
    /* Animate width 0 → target on mount (custom prop drives the keyframe). */
    width: var(--fill);
    animation: member-bar-fill 460ms ease-out both;
}

@keyframes member-bar-fill {
    from {
        width: 0;
    }

    to {
        width: var(--fill);
    }
}

@media (prefers-reduced-motion: reduce) {
    .member-bar {
        /* Snap straight to the final width — no growth animation. */
        animation: none;
    }
}
</style>
