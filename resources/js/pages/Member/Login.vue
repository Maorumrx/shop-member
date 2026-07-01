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
import { router, usePage } from '@inertiajs/vue3';
import liff from '@line/liff';
import { KeyRound, UserPlus } from '@lucide/vue';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import MemberLayout from '@/layouts/MemberLayout.vue';
import type { LineLinkResponse, LineLoginResponse } from '@/types/members';

/**
 * `loading` / `error` / `unconfigured` are the original LINE-verify phases.
 * `needs_link` is the FIRST-TIME LINE user choice screen (§1): the token was
 * verified but no member owns this `line_user_id`, so we ask the customer to
 * either enter a staff-issued claim code or create a fresh account.
 */
type Phase = 'loading' | 'error' | 'unconfigured' | 'needs_link';

const page = usePage();
const liffId = (page.props.lineLiffId ?? '') as string;

const phase = ref<Phase>('loading');
const statusText = ref('กำลังเข้าสู่ระบบผ่าน LINE…');
const errorMessage = ref('');
const canRetry = ref(true);

/* ── needs_link: link-or-create choice screen (§1) ──────────────────────── */
/** Only digits survive; the input is clamped to 6 so `code` is submit-ready. */
const code = ref('');
/** In-flight guard shared by both follow-up posts (disables the whole panel). */
const linkSubmitting = ref(false);
/** Warm, role="alert" error shown under the code field (§1 — invalid/expired). */
const linkError = ref('');

const codeComplete = computed<boolean>(() => code.value.length === 6);

/** Strip non-digits and cap at 6 as the customer types (keeps `code` clean). */
function onCodeInput(event: Event): void {
    const raw = (event.target as HTMLInputElement).value;
    code.value = raw.replace(/\D/g, '').slice(0, 6);
}

/**
 * Both follow-ups carry the server-side `pending_line` session (set by the login
 * POST) via `withCredentials`, so the verified LINE `sub` is never re-trusted
 * from the browser. On `{ ok: true }` we redirect to the dashboard the SAME way
 * the happy path does; a 422 `{ message }` (bad/expired/burned code, or an
 * expired pending session) is shown inline.
 */
async function submitCode(): Promise<void> {
    if (linkSubmitting.value || !codeComplete.value) {
        return;
    }

    linkSubmitting.value = true;
    linkError.value = '';

    try {
        const { data } = await axios.post<LineLinkResponse>(
            '/member/line/submit-code',
            { code: code.value },
            { withCredentials: true },
        );

        if (data.ok) {
            router.visit('/member/dashboard');

            return;
        }

        // Never reached for a real 422 (axios throws) — kept for a defensive 200.
        linkError.value = data.message;
    } catch (e) {
        if (axios.isAxiosError(e) && e.response?.status === 422) {
            linkError.value =
                (e.response.data as { message?: string })?.message ??
                'รหัสไม่ถูกต้องหรือหมดอายุ กรุณาขอรหัสใหม่จากร้าน';
        } else {
            linkError.value = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง';
        }
    } finally {
        linkSubmitting.value = false;
    }
}

/** "I'm new / no code" — create a fresh walk-in account, then to the dashboard. */
async function createNew(): Promise<void> {
    if (linkSubmitting.value) {
        return;
    }

    linkSubmitting.value = true;
    linkError.value = '';

    try {
        const { data } = await axios.post<LineLinkResponse>(
            '/member/line/create-new',
            {},
            { withCredentials: true },
        );

        if (data.ok) {
            router.visit('/member/dashboard');

            return;
        }

        linkError.value = data.message;
    } catch {
        linkError.value = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง';
    } finally {
        linkSubmitting.value = false;
    }
}

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
        const { data } = await axios.post<LineLoginResponse>(
            '/member/line/login',
            { id_token: idToken },
        );

        // First-time LINE user (verified, but no member owns this account yet):
        // switch to the link-or-create choice screen instead of the dashboard (§1).
        if (!data.ok && data.state === 'needs_link') {
            code.value = '';
            linkError.value = '';
            phase.value = 'needs_link';

            return;
        }

        // Matched member — verified + session started server-side -> dashboard.
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
            <h2
                class="font-heading text-base font-semibold text-[var(--color-ink)]"
            >
                ยังไม่ได้ตั้งค่า LIFF
            </h2>
            <p class="text-sm text-[var(--color-ink-muted)]">
                ระบบยังไม่ได้ตั้งค่า LINE LIFF (ขาด LINE_LIFF_ID)
                กรุณาติดต่อผู้ดูแลร้าน
            </p>
        </div>

        <!-- Needs link: first-time LINE user — link an existing account or create one -->
        <div v-else-if="phase === 'needs_link'" class="flex flex-col gap-6">
            <div class="flex flex-col gap-1.5 text-center">
                <h2
                    class="font-heading text-base font-semibold text-[var(--color-ink)]"
                >
                    ยินดีต้อนรับ
                </h2>
                <p class="text-sm text-[var(--color-ink-muted)]">
                    ถ้าคุณเคยซื้อแพ็คเกจที่ร้าน ให้กรอกรหัสที่ได้รับจากพนักงาน
                    เพื่อเชื่อมสิทธิ์ทั้งหมดเข้ากับ LINE ของคุณ
                </p>
            </div>

            <!-- "มีรหัสจากร้าน" — enter the 6-digit staff-issued claim code. -->
            <form class="flex flex-col gap-3" @submit.prevent="submitCode">
                <label
                    for="link-code"
                    class="flex items-center gap-2 text-sm font-medium text-[var(--color-ink)]"
                >
                    <KeyRound
                        class="size-4 text-[var(--color-primary-strong)]"
                        aria-hidden="true"
                    />
                    มีรหัสจากร้าน
                </label>
                <input
                    id="link-code"
                    :value="code"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    maxlength="6"
                    autocomplete="one-time-code"
                    placeholder="000000"
                    :disabled="linkSubmitting"
                    :aria-invalid="linkError !== ''"
                    :aria-describedby="
                        linkError !== '' ? 'link-code-error' : undefined
                    "
                    class="member-code-input h-14 w-full rounded-2xl border border-[var(--color-member-border)] bg-[var(--color-surface)] text-center font-heading text-2xl font-semibold tracking-[0.5em] text-[var(--color-ink)] tabular-nums placeholder:tracking-[0.5em] placeholder:text-[var(--color-disabled-text)]"
                    @input="onCodeInput"
                />

                <p
                    v-if="linkError"
                    id="link-code-error"
                    class="rounded-xl bg-[var(--color-danger-surface)] px-4 py-2.5 text-sm text-[var(--color-ink)]"
                    role="alert"
                >
                    {{ linkError }}
                </p>

                <button
                    type="submit"
                    class="member-cta flex min-h-[44px] items-center justify-center rounded-2xl bg-[var(--color-primary-strong)] px-6 py-3 text-sm font-medium text-white"
                    :disabled="linkSubmitting || !codeComplete"
                >
                    {{ linkSubmitting ? 'กำลังเชื่อมบัญชี…' : 'เชื่อมบัญชี' }}
                </button>
            </form>

            <!-- Divider between the two choices. -->
            <div
                class="flex items-center gap-3 text-xs text-[var(--color-ink-muted)]"
            >
                <span
                    class="h-px flex-1 bg-[var(--color-member-border)]"
                    aria-hidden="true"
                />
                หรือ
                <span
                    class="h-px flex-1 bg-[var(--color-member-border)]"
                    aria-hidden="true"
                />
            </div>

            <!-- "ยังไม่มี / ฉันเป็นลูกค้าใหม่" — lower-emphasis soft button. -->
            <button
                type="button"
                class="member-soft flex min-h-[44px] items-center justify-center gap-2 rounded-2xl bg-[var(--color-member-accent)] px-6 py-3 text-sm font-medium text-[var(--color-ink)]"
                :disabled="linkSubmitting"
                @click="createNew"
            >
                <UserPlus class="size-4" aria-hidden="true" />
                ยังไม่มี / ฉันเป็นลูกค้าใหม่
            </button>
        </div>

        <!-- Error: init / login / verify failed -->
        <div
            v-else
            class="flex flex-col items-center gap-5 rounded-2xl bg-[var(--color-danger-surface)] p-6 text-center"
            role="alert"
        >
            <h2
                class="font-heading text-base font-semibold text-[var(--color-ink)]"
            >
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

.member-cta:disabled {
    cursor: not-allowed;
    filter: none;
    opacity: 0.6;
}

/* Lower-emphasis soft button ("ฉันเป็นลูกค้าใหม่"). */
.member-soft {
    transition:
        filter 160ms ease-out,
        transform 160ms ease-out;
}

.member-soft:hover {
    filter: brightness(0.98);
}

.member-soft:active {
    transform: translateY(1px);
}

.member-soft:focus-visible {
    outline: 2px solid var(--color-focus);
    outline-offset: 2px;
}

.member-soft:disabled {
    cursor: not-allowed;
    filter: none;
    opacity: 0.6;
}

/* 6-digit claim-code field. */
.member-code-input {
    transition: border-color 160ms ease-out;
}

.member-code-input:focus-visible {
    outline: 2px solid var(--color-focus);
    outline-offset: 2px;
    border-color: var(--color-primary-strong);
}

.member-code-input:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

@media (prefers-reduced-motion: reduce) {
    .member-cta,
    .member-soft,
    .member-code-input {
        transition: none;
    }
}
</style>
