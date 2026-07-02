<script setup lang="ts">
/**
 * Member/Dashboard — the LINE-LIFF member home (protected route
 * `member.dashboard`, behind `auth:members`). This is the flagship member
 * surface: it opens inside LINE on a phone, so it is MOBILE-FIRST and rendered
 * through MemberLayout's `feed` variant (a top-aligned column of soft cards on
 * the warm canvas).
 *
 * Vertical order: greeting → balance hero → active lots → history → a demoted
 * (low-emphasis) logout at the very bottom. Every number comes from the backend
 * (DashboardController + the shared MemberWalletQuery); the balance + history are
 * in baht, and the history feed carries NO staff names.
 *
 * Motion: staggered CSS entrance (fade + rise) with ~60–80ms delay increments,
 * capped ~350ms; all animation collapses to its final state under
 * prefers-reduced-motion (matching MemberLayout).
 */
import { Head, router } from '@inertiajs/vue3';
import { CalendarPlus } from '@lucide/vue';
import MemberAvatar from '@/components/member/MemberAvatar.vue';
import MemberBalanceCard from '@/components/member/MemberBalanceCard.vue';
import MemberHistoryList from '@/components/member/MemberHistoryList.vue';
import MemberLotCard from '@/components/member/MemberLotCard.vue';
import MemberLayout from '@/layouts/MemberLayout.vue';
import type {
    MemberProfile,
    MemberWalletHistoryRow,
    WalletLot,
} from '@/types/members';

defineProps<{
    member: MemberProfile;
    balance: string;
    lots: WalletLot[];
    history: MemberWalletHistoryRow[];
}>();

function logout(): void {
    router.post('/member/logout');
}

/** Low-emphasis entry point into the Phase 7 booking (จองคิว) surface. */
function goToBooking(): void {
    router.get('/member/bookings');
}
</script>

<template>
    <Head title="สมาชิก" />

    <MemberLayout variant="feed">
        <div class="flex flex-col gap-5">
            <!-- Greeting -->
            <header class="member-in flex items-center gap-3">
                <MemberAvatar
                    :name="member.name"
                    :avatar-url="member.avatar_url"
                />
                <div class="flex flex-col">
                    <span class="text-sm text-[var(--color-ink-muted)]">
                        สวัสดี
                    </span>
                    <h1
                        class="font-heading text-xl font-semibold text-[var(--color-ink)]"
                    >
                        {{ member.name }}
                    </h1>
                </div>
            </header>

            <!-- Balance hero -->
            <div class="member-in" style="--delay: 60ms">
                <MemberBalanceCard :balance="balance" />
            </div>

            <!-- Active lots -->
            <section
                v-if="lots.length > 0"
                class="flex flex-col gap-3"
                aria-labelledby="member-lots-title"
            >
                <h2
                    id="member-lots-title"
                    class="member-in font-heading text-sm font-semibold text-[var(--color-ink-muted)]"
                    style="--delay: 120ms"
                >
                    เครดิตของคุณ
                </h2>
                <div
                    v-for="(lot, i) in lots"
                    :key="lot.id"
                    class="member-in"
                    :style="{
                        '--delay': `${Math.min(120 + (i + 1) * 60, 350)}ms`,
                    }"
                >
                    <MemberLotCard :lot="lot" />
                </div>
            </section>

            <!-- History -->
            <section
                class="flex flex-col gap-3"
                aria-labelledby="member-history-title"
            >
                <h2
                    id="member-history-title"
                    class="member-in font-heading text-sm font-semibold text-[var(--color-ink-muted)]"
                    style="--delay: 300ms"
                >
                    ประวัติการใช้งาน
                </h2>
                <div class="member-in" style="--delay: 340ms">
                    <MemberHistoryList :history="history" />
                </div>
            </section>

            <!-- Booking entry point (low-emphasis, soft accent) -->
            <div class="member-in mt-2" style="--delay: 345ms">
                <button
                    type="button"
                    class="member-cta flex w-full items-center justify-center gap-2 rounded-2xl bg-[var(--color-member-accent)] px-6 py-3 text-sm font-medium text-[var(--color-ink)]"
                    @click="goToBooking"
                >
                    <CalendarPlus class="size-4" aria-hidden="true" />
                    จองคิว
                </button>
            </div>

            <!-- Demoted logout (low-emphasis, soft accent) -->
            <div class="member-in" style="--delay: 350ms">
                <button
                    type="button"
                    class="member-cta w-full rounded-2xl bg-[var(--color-member-accent)] px-6 py-3 text-sm font-medium text-[var(--color-ink)]"
                    @click="logout"
                >
                    ออกจากระบบ
                </button>
            </div>
        </div>
    </MemberLayout>
</template>

<style scoped>
.member-in {
    /* Staggered soft entrance — fade + gentle rise, per-element `--delay`. */
    animation: member-card-in 200ms ease-out both;
    animation-delay: var(--delay, 0ms);
}

@keyframes member-card-in {
    from {
        opacity: 0;
        transform: translateY(8px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.member-cta {
    min-height: 44px;
    transition:
        filter 160ms ease-out,
        transform 160ms ease-out;
}

.member-cta:hover {
    filter: brightness(0.97);
}

.member-cta:active {
    transform: translateY(1px);
}

.member-cta:focus-visible {
    outline: 2px solid var(--color-focus);
    outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
    .member-in {
        /* Snap to final state instantly — no fade/rise. */
        animation: none;
    }

    .member-cta {
        transition: none;
    }
}
</style>
