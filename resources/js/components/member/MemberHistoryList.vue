<script setup lang="ts">
/**
 * MemberHistoryList — the member's recent activity feed inside one wrapping
 * card. Each row: a reason dot + icon + Thai label, the item name + short Thai
 * date, and the signed delta on the right. NO staff names (the member view never
 * receives them).
 *
 * The server already caps history at 50; we show the most recent few here and
 * slice client-side. Because we intentionally cap, there is NO "ดูทั้งหมด" link
 * (it would go nowhere).
 *
 * a11y: rendered as a `<ul>`/`<li>`; each reason state pairs a token color with
 * an icon AND a Thai label; rows are padded to a ≥44px touch target.
 */
import { Clock, Plus, Scissors } from '@lucide/vue';
import { computed, type Component } from 'vue';
import { formatThaiDateTime, reasonLabel } from '@/lib/thai';
import type { HistoryReason, MemberHistoryRow } from '@/types/members';

const props = withDefaults(
    defineProps<{
        history: MemberHistoryRow[];
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
    /** Dot color token utility (never white text — used as a small dot only). */
    dotClass: string;
};

const REASON_STYLES: Record<'redeem' | 'expire' | 'refund', ReasonStyle> = {
    redeem: { icon: Scissors, dotClass: 'bg-[var(--color-primary-strong)]' },
    expire: { icon: Clock, dotClass: 'bg-[var(--color-warning)]' },
    refund: { icon: Plus, dotClass: 'bg-[var(--color-success)]' },
};

function reasonStyle(reason: HistoryReason): ReasonStyle {
    return (
        REASON_STYLES[reason as keyof typeof REASON_STYLES] ??
        REASON_STYLES.redeem
    );
}

/** Signed delta render — `+1` for a positive (refund), `-1` otherwise. */
function formatDelta(delta: number): string {
    return delta > 0 ? `+${delta}` : String(delta);
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
                        {{ row.item_name ?? '—' }}
                    </span>
                    <span class="text-xs text-[var(--color-ink-muted)]">
                        {{ reasonLabel(row.reason) }} ·
                        {{ formatThaiDateTime(row.created_at) }}
                    </span>
                </div>

                <span
                    class="shrink-0 font-heading text-base font-semibold tabular-nums"
                    :class="
                        row.delta > 0
                            ? 'text-[var(--color-success)]'
                            : 'text-[var(--color-ink)]'
                    "
                >
                    {{ formatDelta(row.delta) }}
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
