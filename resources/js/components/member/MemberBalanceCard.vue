<script setup lang="ts">
/**
 * MemberBalanceCard — the dashboard HERO: the member's single spendable credit
 * balance, in baht. One big, legible ink number on the warm surface (the money
 * wallet has ONE balance now — no per-type breakdown).
 *
 * a11y: the balance is `--color-ink` (NOT accent) so it stays legible; the accent
 * token appears only as a small decorative chip.
 */
import { Wallet } from '@lucide/vue';
import { computed } from 'vue';
import { formatBaht } from '@/lib/money';

const props = defineProps<{
    /** The spendable wallet balance as a decimal-2 STRING (e.g. "1290.00"). */
    balance: string;
}>();

const isEmpty = computed(() => Number.parseFloat(props.balance ?? '0') <= 0);
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
            เครดิตคงเหลือ
        </h2>

        <!-- Empty: neutral soft state, never red. -->
        <div
            v-if="isEmpty"
            class="mt-5 flex flex-col items-center gap-3 py-4 text-center"
        >
            <span
                class="flex size-14 items-center justify-center rounded-full bg-[var(--color-member-accent)]"
            >
                <Wallet
                    class="size-6 text-[var(--color-ink)]"
                    aria-hidden="true"
                />
            </span>
            <p
                class="font-heading text-base font-semibold text-[var(--color-ink)]"
            >
                ยังไม่มีเครดิต
            </p>
            <p class="text-sm text-[var(--color-ink-muted)]">
                เมื่อเติมเครดิต ยอดจะแสดงที่นี่
            </p>
        </div>

        <!-- Balance hero. -->
        <div v-else class="mt-4 flex flex-col items-center gap-1 text-center">
            <span
                class="mb-2 h-1.5 w-10 rounded-full bg-[var(--color-member-accent)]"
                aria-hidden="true"
            />
            <p
                class="font-heading text-5xl font-semibold text-[var(--color-ink)] tabular-nums"
            >
                {{ formatBaht(props.balance) }}
            </p>
            <p class="text-sm text-[var(--color-ink-muted)]">
                ใช้จ่ายได้ทุกบริการ
            </p>
        </div>
    </section>
</template>
