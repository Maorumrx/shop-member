<script setup lang="ts">
/**
 * Member/Login — the LINE LIFF entry page (PUBLIC route `member.login`, `/member`).
 *
 * Flow:
 *   1. liff.init({ liffId })
 *   2. if !liff.isLoggedIn() -> liff.login()  (redirects out to LINE, then back)
 *   3. grab liff.getIDToken() and POST it to /member/line/login (JSON, axios)
 *   4. on { ok: true } -> Inertia visit to the member dashboard
 *
 * The login POST returns plain JSON (NOT an Inertia response), so we use axios
 * rather than an Inertia visit for it. axios echoes Laravel's XSRF-TOKEN cookie
 * as the X-XSRF-TOKEN header (the cookie is already set because this page was
 * served by Inertia under the `web` middleware group). The post-login redirect
 * is a normal Inertia visit so the dashboard loads with shared props.
 */
import liff from '@line/liff';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, ref } from 'vue';
import MemberLayout from '@/layouts/MemberLayout.vue';

type Phase = 'loading' | 'error' | 'unconfigured';

const page = usePage();
const liffId = (page.props.lineLiffId ?? '') as string;

const phase = ref<Phase>('loading');
const statusText = ref('กำลังเข้าสู่ระบบผ่าน LINE…');
const errorMessage = ref('');
const canRetry = ref(true);

// axios sends the XSRF-TOKEN cookie back as the X-XSRF-TOKEN header so Laravel's
// CSRF check passes on this same-origin POST.
axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;

async function authenticate(): Promise<void> {
    // Guard: LIFF not configured (LINE_LIFF_ID missing) — fail loudly, don't init.
    if (!liffId) {
        phase.value = 'unconfigured';
        return;
    }

    phase.value = 'loading';
    statusText.value = 'กำลังเข้าสู่ระบบผ่าน LINE…';
    errorMessage.value = '';
    canRetry.value = true;

    try {
        await liff.init({ liffId });

        // Not signed in yet -> bounce to LINE. This call redirects the browser,
        // so nothing below runs until we come back already logged in.
        if (!liff.isLoggedIn()) {
            liff.login();
            return;
        }

        const idToken = liff.getIDToken();
        if (!idToken) {
            throw new Error('no-id-token');
        }

        statusText.value = 'กำลังยืนยันตัวตน…';
        await axios.post('/member/line/login', { id_token: idToken });

        // Verified + session started server-side -> load the member dashboard.
        router.visit('/member/dashboard');
    } catch (e) {
        // Breadcrumb for debugging from inside the LINE in-app browser console.
        // The id_token is not part of the error, so nothing sensitive is logged.
        console.error('[member-login]', e);

        phase.value = 'error';

        // 403 = the account is disabled/unavailable — retrying can never succeed,
        // so surface the server's reason and HIDE the retry button.
        if (axios.isAxiosError(e) && e.response?.status === 403) {
            canRetry.value = false;
            errorMessage.value =
                (e.response.data as { message?: string })?.message ??
                'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อร้าน';
            return;
        }

        // Everything else (opened outside LINE, liff.init failure, no ID token,
        // 422 verify failure, network) is retryable.
        canRetry.value = true;
        errorMessage.value =
            'ไม่สามารถเข้าสู่ระบบผ่าน LINE ได้ กรุณาเปิดหน้านี้จากแอป LINE แล้วลองอีกครั้ง';
    }
}

function retry(): void {
    void authenticate();
}

onMounted(() => {
    void authenticate();
});
</script>

<template>
    <MemberLayout title="เข้าสู่ระบบสมาชิก">
        <!-- Loading: signing in through LINE -->
        <div
            v-if="phase === 'loading'"
            class="flex flex-col items-center gap-5 py-4 text-center"
            role="status"
            aria-live="polite"
        >
            <span
                class="size-10 animate-spin rounded-full border-3 border-[var(--color-member-border)] border-t-[var(--color-primary-strong)]"
                aria-hidden="true"
            />
            <p class="text-sm text-[var(--color-ink-muted)]">
                {{ statusText }}
            </p>
        </div>

        <!-- Unconfigured: LINE_LIFF_ID not set -->
        <div
            v-else-if="phase === 'unconfigured'"
            class="flex flex-col items-center gap-4 rounded-2xl bg-[var(--color-warning-surface)] p-6 text-center"
        >
            <h2 class="font-heading text-base font-semibold text-[var(--color-ink)]">
                ยังไม่ได้ตั้งค่า LIFF
            </h2>
            <p class="text-sm text-[var(--color-ink-muted)]">
                ระบบยังไม่ได้ตั้งค่า LINE LIFF (ขาด LINE_LIFF_ID)
                กรุณาติดต่อผู้ดูแลร้าน
            </p>
        </div>

        <!-- Error: init / login / verify failed -->
        <div
            v-else
            class="flex flex-col items-center gap-5 rounded-2xl bg-[var(--color-danger-surface)] p-6 text-center"
            role="alert"
        >
            <h2 class="font-heading text-base font-semibold text-[var(--color-ink)]">
                เข้าสู่ระบบไม่สำเร็จ
            </h2>
            <p class="text-sm text-[var(--color-ink)]">
                {{ errorMessage }}
            </p>
            <button
                v-if="canRetry"
                type="button"
                class="member-cta rounded-2xl bg-[var(--color-primary-strong)] px-6 py-2.5 text-sm font-medium text-white"
                @click="retry"
            >
                ลองอีกครั้ง
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
    filter: brightness(1.05);
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
