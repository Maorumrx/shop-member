<script setup lang="ts">
/**
 * MemberBalanceCard — the dashboard HERO: the member's live "remaining by type"
 * balance. Big, legible ink numbers on the warm surface.
 *
 * Layout: exactly one type → a centered hero row; 2–5 types → a vertical stack
 * with thin member-border dividers (numbers stay LARGE — we deliberately do NOT
 * shrink into a grid). Empty → a soft, neutral state (no red).
 *
 * a11y: the remaining number is `--color-ink` (NOT accent) so it is legible; the
 * accent token appears only as a small decorative chip per row.
 */
import { Wallet } from '@lucide/vue';
import { computed } from 'vue';
import type { BalanceLine } from '@/types/members';

const props = defineProps<{
    balanceByType: BalanceLine[];
}>();

const isSingle = computed(() => props.balanceByType.length === 1);
</script>

<template>
    <section
        class="rounded-3xl border border-[var(--color-member-border)] bg-[var(--color-surface)] p-6 shadow-[var(--shadow-card)]"
        aria-labelledby="member-balance-title"
    >
        <h2
            id="member-balance-title"
            class="font-heading text-sm font-semibold text-[var(--color-ink-muted)]"
        >
            สิทธิ์คงเหลือของคุณ
        </h2>

        <!-- Empty: neutral soft state, never red. -->
        <div
            v-if="balanceByType.length === 0"
            class="mt-5 flex flex-col items-center gap-3 py-4 text-center"
        >
            <span
                class="flex size-14 items-center justify-center rounded-full bg-[var(--color-member-accent)]"
            >
                <Wallet class="size-6 text-[var(--color-ink)]" aria-hidden="true" />
            </span>
            <p class="font-heading text-base font-semibold text-[var(--color-ink)]">
                ยังไม่มีสิทธิ์คงเหลือ
            </p>
            <p class="text-sm text-[var(--color-ink-muted)]">
                เมื่อซื้อแพ็กเกจ สิทธิ์จะแสดงที่นี่
            </p>
        </div>

        <!-- Single type: centered hero. -->
        <div
            v-else-if="isSingle"
            class="mt-4 flex flex-col items-center gap-1 text-center"
        >
            <span
                class="mb-2 h-1.5 w-10 rounded-full bg-[var(--color-member-accent)]"
                aria-hidden="true"
            />
            <p
                class="font-heading text-5xl font-semibold text-[var(--color-ink)] tabular-nums"
            >
                {{ balanceByType[0].remaining }}
                <span
                    class="font-sans text-lg font-normal text-[var(--color-ink-muted)]"
                >
                    ครั้ง
                </span>
            </p>
            <p class="text-base font-medium text-[var(--color-ink)]">
                {{ balanceByType[0].item_name }}
            </p>
        </div>

        <!-- 2–5 types: vertical stack, numbers kept large. -->
        <ul v-else class="mt-4 flex flex-col">
            <li
                v-for="(line, i) in balanceByType"
                :key="line.item_code"
                class="flex items-center justify-between gap-4 py-3"
                :class="
                    i > 0 ? 'border-t border-[var(--color-member-border)]' : ''
                "
            >
                <div class="flex items-center gap-3">
                    <span
                        class="h-8 w-1.5 shrink-0 rounded-full bg-[var(--color-member-accent)]"
                        aria-hidden="true"
                    />
                    <span class="text-base font-medium text-[var(--color-ink)]">
                        {{ line.item_name }}
                    </span>
                </div>
                <p
                    class="font-heading text-4xl font-semibold text-[var(--color-ink)] tabular-nums"
                >
                    {{ line.remaining }}
                    <span
                        class="font-sans text-sm font-normal text-[var(--color-ink-muted)]"
                    >
                        ครั้ง
                    </span>
                </p>
            </li>
        </ul>
    </section>
</template>
