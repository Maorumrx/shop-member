<script setup lang="ts">
/**
 * MemberHistoryList — the member's recent wallet activity, in one wrapping card.
 * Each row: a reason dot + icon + Thai label, the short Thai date (+ any note),
 * and the SIGNED baht delta on the right. NO staff names (the member view never
 * receives them).
 *
 * The server caps history at 50; we show the most recent few and slice client-
 * side (no "ดูทั้งหมด" link — it would go nowhere). a11y: `<ul>`/`<li>`, each
 * reason pairs a token color with an icon AND a Thai label; rows are ≥44px.
 */
import { Clock, Gift, Minus, Plus, RotateCcw, Settings2 } from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import { formatSignedBaht } from '@/lib/money';
import { formatThaiDateTime, reasonLabel } from '@/lib/thai';
import type { HistoryReason, MemberWalletHistoryRow } from '@/types/members';

const props = withDefaults(
    defineProps<{
        history: MemberWalletHistoryRow[];
        /** How many most-recent rows to show (server already caps at 50). */
        limit?: number;
    }>(),
    {
        limit: 8,
    },
);

const rows = computed(() => props.history.slice(0, props.limit));

type ReasonStyle = {
    icon: Component;
    /** Dot color token utility (used as a small filled dot only). */
    dotClass: string;
};

const REASON_STYLES: Record<
    'topup' | 'bonus' | 'debit' | 'refund' | 'expire' | 'adjust',
    ReasonStyle
> = {
    topup: { icon: Plus, dotClass: 'bg-[var(--color-success)]' },
    bonus: { icon: Gift, dotClass: 'bg-[var(--color-success)]' },
    debit: { icon: Minus, dotClass: 'bg-[var(--color-primary-strong)]' },
    refund: { icon: RotateCcw, dotClass: 'bg-[var(--color-primary-strong)]' },
    expire: { icon: Clock, dotClass: 'bg-[var(--color-warning)]' },
    adjust: { icon: Settings2, dotClass: 'bg-[var(--color-primary-strong)]' },
};

function reasonStyle(reason: HistoryReason): ReasonStyle {
    return (
        REASON_STYLES[reason as keyof typeof REASON_STYLES] ??
        REASON_STYLES.debit
    );
}

/** Whether a signed decimal string is positive (drives the delta color). */
function isPositive(value: string): boolean {
    return Number.parseFloat(value) > 0;
}
</script>

<template>
    <section
        class="rounded-2xl border border-[var(--color-member-border)] bg-[var(--color-surface)] p-2 shadow-[var(--shadow-soft)]"
    >
        <ul v-if="rows.length > 0" class="flex flex-col">
            <li
                v-for="(row, i) in rows"
                :key="row.id"
                class="flex items-center gap-3 px-3 py-3"
                :class="
                    i > 0 ? 'border-t border-[var(--color-member-border)]' : ''
                "
            >
                <!-- Reason dot + icon (color always paired with icon + label). -->
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-full text-white"
                    :class="reasonStyle(row.reason).dotClass"
                >
                    <component
                        :is="reasonStyle(row.reason).icon"
                        class="size-4"
                        aria-hidden="true"
                    />
                </span>

                <div class="flex min-w-0 flex-1 flex-col">
                    <span class="truncate text-sm text-[var(--color-ink)]">
                        {{ reasonLabel(row.reason) }}
                    </span>
                    <span class="truncate text-xs text-[var(--color-ink-muted)]">
                        {{ formatThaiDateTime(row.created_at) }}
                        <template v-if="row.note"> · {{ row.note }} </template>
                    </span>
                </div>

                <span
                    class="shrink-0 font-heading text-base font-semibold tabular-nums"
                    :class="
                        isPositive(row.delta)
                            ? 'text-[var(--color-success)]'
                            : 'text-[var(--color-ink)]'
                    "
                >
                    {{ formatSignedBaht(row.delta) }}
                </span>
            </li>
        </ul>

        <p
            v-else
            class="px-3 py-8 text-center text-sm text-[var(--color-ink-muted)]"
        >
            ยังไม่มีประวัติการใช้งาน
        </p>
    </section>
</template>
