<script setup lang="ts">
/**
 * MemberLotCard — one owned lot (a purchase) with its items. Header carries the
 * package name + purchased/expiry dates; the body lists each item with a thin
 * remaining/total progress bar.
 *
 * State cues (a11y — color always paired with icon + label via MemberStateBadge):
 * - expires_at === null → a "never-expires" badge.
 * - is_near_expiry      → a "near-expiry" badge PLUS a slim WARNING left accent
 *   bar (badge + bar; we do NOT flood the whole card — near-expiry is WARNING,
 *   not DANGER).
 * Only ACTIVE lots reach here (backend filter), so there are no expired/used-up
 * whole-lot states — item-level 0-remaining just shows an empty accent bar.
 *
 * Motion: each progress bar animates width 0 → target on mount (`--fill` custom
 * property drives the keyframe); reduced-motion snaps to full width.
 */
import { computed } from 'vue';
import MemberStateBadge from '@/components/member/MemberStateBadge.vue';
import { formatThaiDate } from '@/lib/thai';
import type { MemberLot } from '@/types/members';

const props = defineProps<{
    lot: MemberLot;
}>();

/** Package name fallback — a lot can outlive its catalog package (SET NULL). */
const packageName = computed(() => props.lot.package_name ?? 'แพ็กเกจ');

/** Fill ratio (0–1) for an item's remaining/total bar; guards divide-by-zero. */
function fillRatio(remaining: number, total: number): number {
    if (total <= 0) {
        return 0;
    }

    return Math.min(Math.max(remaining / total, 0), 1);
}
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

        <!-- Header: package name + dates + state badge. -->
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="flex flex-col gap-1">
                <h3
                    class="font-heading text-base font-semibold text-[var(--color-ink)]"
                >
                    {{ packageName }}
                </h3>
                <p class="text-xs text-[var(--color-ink-muted)]">
                    ซื้อเมื่อ {{ formatThaiDate(lot.purchased_at) }}
                    <template v-if="lot.expires_at">
                        · หมดอายุ {{ formatThaiDate(lot.expires_at) }}
                    </template>
                </p>
            </div>

            <MemberStateBadge
                v-if="lot.expires_at === null"
                state="never-expires"
            />
            <MemberStateBadge
                v-else-if="lot.is_near_expiry"
                state="near-expiry"
            />
        </div>

        <!-- Items: name + remaining/total + thin progress bar. -->
        <ul class="mt-4 flex flex-col gap-3">
            <li
                v-for="(item, i) in lot.items"
                :key="`${item.item_name}-${i}`"
                class="flex flex-col gap-1.5"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-[var(--color-ink)]">
                            {{ item.item_name }}
                        </span>
                        <MemberStateBadge
                            v-if="item.item_type === 'addon'"
                            state="addon"
                        />
                    </div>
                    <span class="text-sm tabular-nums">
                        <span class="font-heading font-semibold text-[var(--color-ink)]">
                            {{ item.qty_remaining }}
                        </span>
                        <span class="text-[var(--color-ink-muted)]">
                            / {{ item.qty_total }}
                        </span>
                    </span>
                </div>
                <div
                    class="h-1.5 w-full overflow-hidden rounded-full bg-[var(--color-member-border)]"
                >
                    <span
                        class="member-bar block h-full rounded-full bg-[var(--color-member-accent)]"
                        :style="{
                            '--fill': `${
                                fillRatio(item.qty_remaining, item.qty_total) *
                                100
                            }%`,
                        }"
                    />
                </div>
            </li>
        </ul>
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
