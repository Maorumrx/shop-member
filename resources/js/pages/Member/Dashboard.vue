<script setup lang="ts">
/**
 * Member/Dashboard — placeholder member home (protected route `member.dashboard`,
 * behind `auth:members`).
 *
 * For now this is a soft welcome card + sign-out. Real member props (name,
 * avatar, points) and the entitlement cards land in Phase 6 — see TODO below.
 */
import { Head, router } from '@inertiajs/vue3';
import MemberLayout from '@/layouts/MemberLayout.vue';

// TODO(Phase 6): the `members` guard user is NOT in the default `auth.user`
// shared prop (that is the admin/web guard). Share the authenticated member
// (name, avatar_url, points) from a member-specific controller / middleware and
// type it, then greet by name here. Entitlement cards (active passes, balances,
// near-expiry warnings) render below using the state-surface tokens.

function logout(): void {
    router.post('/member/logout');
}
</script>

<template>
    <Head title="สมาชิก" />

    <MemberLayout title="ยินดีต้อนรับ">
        <div class="flex flex-col items-center gap-6 text-center">
            <div class="flex flex-col gap-2">
                <h2 class="text-lg font-semibold text-[var(--color-ink)]">
                    เข้าสู่ระบบสำเร็จ
                </h2>
                <p class="text-sm text-[var(--color-ink-muted)]">
                    คุณเข้าสู่ระบบสมาชิกผ่าน LINE เรียบร้อยแล้ว
                    สิทธิประโยชน์และบัตรสมาชิกจะแสดงที่นี่เร็ว ๆ นี้
                </p>
            </div>

            <!-- TODO(Phase 6): entitlement cards go here (state-surface tokens). -->

            <button
                type="button"
                class="member-cta w-full rounded-2xl bg-[var(--color-member-accent)] px-6 py-2.5 text-sm font-medium text-[var(--color-ink)]"
                @click="logout"
            >
                ออกจากระบบ
            </button>
        </div>
    </MemberLayout>
</template>

<style scoped>
.member-cta {
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
    .member-cta {
        transition: none;
    }
}
</style>
