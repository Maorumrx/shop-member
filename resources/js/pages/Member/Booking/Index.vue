<script setup lang="ts">
/**
 * Member/Booking/Index — the LINE-LIFF "จองคิว" surface (route
 * `member.bookings`, behind `auth:members`). MOBILE-FIRST, rendered through
 * MemberLayout's `feed` variant (a top-aligned column of soft cards on the warm
 * canvas), mirroring the dashboard's structure, motion and a11y.
 *
 * Two parts, top to bottom:
 *  1. จองคิวใหม่ — a progressive flow: branch → date → slot → service → confirm.
 *     Availability is fetched with axios (NOT Inertia) from
 *     GET /member/bookings/availability the moment a branch + date are chosen;
 *     slots render as tappable chips showing time + remaining ("เหลือ 2 คิว").
 *     Confirm posts to `member.bookings.store` (flash toast + redirect).
 *  2. การจองของฉัน — `upcoming` (cancellable rows get a ยกเลิก button →
 *     router.delete on `member.bookings.cancel`) then `recent`, each a soft row
 *     with service + branch + Thai date/time + a status pill.
 *
 * Motion: staggered `.member-in` entrance, collapsing under
 * prefers-reduced-motion (matching MemberLayout / the dashboard).
 */
import { Head, router } from '@inertiajs/vue3';
import {
    CalendarDays,
    Check,
    ChevronLeft,
    MapPin,
    Sparkles,
} from '@lucide/vue';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import MemberBookingStatusBadge from '@/components/member/MemberBookingStatusBadge.vue';
import MemberLayout from '@/layouts/MemberLayout.vue';
import { formatThaiDate, formatThaiTimeRange } from '@/lib/thai';
import { isCancellable } from '@/types/bookings';
import type {
    AvailabilityResponse,
    BookingBranch,
    BookingService,
    BookingSlot,
    MemberBookingRow,
} from '@/types/bookings';

const props = defineProps<{
    upcoming: MemberBookingRow[];
    recent: MemberBookingRow[];
    branches: BookingBranch[];
    services: BookingService[];
}>();

// axios mirrors Member/Login: send the session cookie + XSRF header so the
// members-guarded availability endpoint recognizes us.
axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;

/* ── Booking flow state ─────────────────────────────────────────────────── */
// Auto-select the branch when there is exactly one (skip that step entirely).
const selectedBranchId = ref<number | null>(
    props.branches.length === 1 ? props.branches[0].id : null,
);
const selectedDate = ref<string | null>(null);
const selectedSlot = ref<BookingSlot | null>(null);
const selectedService = ref<string | null>(
    props.services.length === 1 ? props.services[0].item_code : null,
);
const note = ref('');

const slots = ref<BookingSlot[]>([]);
const slotsLoading = ref(false);
const slotsError = ref(false);
const submitting = ref(false);

const selectedBranch = computed<BookingBranch | null>(
    () => props.branches.find((b) => b.id === selectedBranchId.value) ?? null,
);

/** Whether the branch step is shown (hidden when auto-selected to a single branch). */
const showBranchStep = computed(() => props.branches.length > 1);

/**
 * The day picker's range: today … today + max_advance_days (inclusive), driven
 * by the chosen branch's config. Rendered as local `YYYY-MM-DD` keys so we never
 * hand the server a timezone-shifted date.
 */
function toDateKey(d: Date): string {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');

    return `${y}-${m}-${day}`;
}

const dayOptions = computed<{ key: string; iso: string }[]>(() => {
    const branch = selectedBranch.value;

    if (!branch) {
        return [];
    }

    const span = Math.max(0, branch.max_advance_days);
    const out: { key: string; iso: string }[] = [];
    const base = new Date();
    base.setHours(0, 0, 0, 0);

    for (let i = 0; i <= span; i++) {
        const d = new Date(base);
        d.setDate(base.getDate() + i);
        const key = toDateKey(d);
        out.push({ key, iso: d.toISOString() });
    }

    return out;
});

/** Weekday label (short Thai) for a day chip, e.g. "จันทร์". */
const weekdayFormatter = new Intl.DateTimeFormat('th-TH', { weekday: 'short' });
function weekdayLabel(iso: string): string {
    return weekdayFormatter.format(new Date(iso));
}

/** Compact "day short-month" (no year) for the day chip, e.g. "3 ก.ค.". */
const dayChipFormatter = new Intl.DateTimeFormat('th-TH', {
    day: 'numeric',
    month: 'short',
});
function dayChipLabel(iso: string): string {
    return dayChipFormatter.format(new Date(iso));
}

/** Whether the flow is ready to confirm. */
const canConfirm = computed(
    () =>
        selectedBranchId.value !== null &&
        selectedSlot.value !== null &&
        !selectedSlot.value.is_full &&
        selectedService.value !== null &&
        !submitting.value,
);

/* ── Availability fetch (axios, on branch + date) ───────────────────────── */
async function loadSlots(): Promise<void> {
    // Reset the downstream selection whenever the inputs change.
    slots.value = [];
    selectedSlot.value = null;
    slotsError.value = false;

    if (selectedBranchId.value === null || !selectedDate.value) {
        return;
    }

    slotsLoading.value = true;

    try {
        const { data } = await axios.get<AvailabilityResponse>(
            '/member/bookings/availability',
            {
                params: {
                    branch_id: selectedBranchId.value,
                    date: selectedDate.value,
                },
            },
        );
        slots.value = data.slots ?? [];
    } catch {
        slotsError.value = true;
    } finally {
        slotsLoading.value = false;
    }
}

// Re-fetch whenever branch or date changes.
watch([selectedBranchId, selectedDate], () => {
    void loadSlots();
});

/* ── Step handlers ──────────────────────────────────────────────────────── */
function chooseBranch(id: number): void {
    selectedBranchId.value = id;
    selectedDate.value = null;
    slots.value = [];
    selectedSlot.value = null;
}

function chooseDate(key: string): void {
    selectedDate.value = key;
}

function chooseSlot(slot: BookingSlot): void {
    if (slot.is_full) {
        return;
    }

    selectedSlot.value = slot;
}

function confirm(): void {
    if (
        !canConfirm.value ||
        selectedBranchId.value === null ||
        selectedSlot.value === null ||
        selectedService.value === null
    ) {
        return;
    }

    submitting.value = true;

    router.post(
        '/member/bookings',
        {
            branch_id: selectedBranchId.value,
            item_code: selectedService.value,
            scheduled_start: selectedSlot.value.start,
            note: note.value.trim() === '' ? null : note.value.trim(),
        },
        {
            preserveScroll: true,
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

/* ── Cancel an upcoming booking ─────────────────────────────────────────── */
const cancellingId = ref<number | null>(null);

function cancel(booking: MemberBookingRow): void {
    if (!isCancellable(booking.status) || cancellingId.value !== null) {
        return;
    }

    if (!window.confirm('ต้องการยกเลิกการจองนี้ใช่หรือไม่?')) {
        return;
    }

    cancellingId.value = booking.id;

    router.delete(`/member/bookings/${booking.id}`, {
        preserveScroll: true,
        onFinish: () => {
            cancellingId.value = null;
        },
    });
}
</script>

<template>
    <Head title="จองคิว" />

    <MemberLayout variant="feed">
        <div class="flex flex-col gap-5">
            <!-- Header with a back link to the dashboard -->
            <header class="member-in flex items-center gap-3">
                <button
                    type="button"
                    class="member-tap flex size-10 shrink-0 items-center justify-center rounded-full bg-[var(--color-member-accent)] text-[var(--color-ink)]"
                    aria-label="กลับหน้าหลัก"
                    @click="router.get('/member/dashboard')"
                >
                    <ChevronLeft class="size-5" aria-hidden="true" />
                </button>
                <div class="flex flex-col">
                    <span class="text-sm text-[var(--color-ink-muted)]">
                        นัดหมาย
                    </span>
                    <h1
                        class="font-heading text-xl font-semibold text-[var(--color-ink)]"
                    >
                        จองคิว
                    </h1>
                </div>
            </header>

            <!-- ── จองคิวใหม่ ──────────────────────────────────────────── -->
            <section
                class="member-in flex flex-col gap-4 rounded-3xl border border-[var(--color-member-border)] bg-[var(--color-surface)] p-5 shadow-[var(--shadow-card)]"
                style="--delay: 60ms"
                aria-labelledby="member-new-booking-title"
            >
                <h2
                    id="member-new-booking-title"
                    class="font-heading text-base font-semibold text-[var(--color-ink)]"
                >
                    จองคิวใหม่
                </h2>

                <!-- No bookable branch at all -->
                <p
                    v-if="branches.length === 0"
                    class="rounded-2xl bg-[var(--color-disabled-bg)] px-4 py-6 text-center text-sm text-[var(--color-ink-muted)]"
                >
                    ขณะนี้ยังไม่เปิดให้จองคิว
                </p>

                <template v-else>
                    <!-- Step 1 — Branch (skipped when only one bookable) -->
                    <fieldset v-if="showBranchStep" class="flex flex-col gap-2">
                        <legend
                            class="mb-1 flex items-center gap-1.5 text-sm font-medium text-[var(--color-ink-muted)]"
                        >
                            <MapPin class="size-4" aria-hidden="true" />
                            เลือกสาขา
                        </legend>
                        <div class="flex flex-col gap-2">
                            <button
                                v-for="branch in branches"
                                :key="branch.id"
                                type="button"
                                class="member-tap flex items-center justify-between rounded-2xl border px-4 py-3 text-left text-sm"
                                :class="
                                    selectedBranchId === branch.id
                                        ? 'border-[var(--color-primary-strong)] bg-[var(--color-member-accent)] text-[var(--color-ink)]'
                                        : 'border-[var(--color-member-border)] bg-[var(--color-surface)] text-[var(--color-ink)]'
                                "
                                :aria-pressed="selectedBranchId === branch.id"
                                @click="chooseBranch(branch.id)"
                            >
                                <span class="font-medium">{{
                                    branch.name
                                }}</span>
                                <Check
                                    v-if="selectedBranchId === branch.id"
                                    class="size-4 text-[var(--color-primary-strong)]"
                                    aria-hidden="true"
                                />
                            </button>
                        </div>
                    </fieldset>

                    <!-- Step 2 — Date -->
                    <!-- min-w-0 defeats the <fieldset> default `min-width: min-content`,
                         which otherwise refuses to shrink and lets the day row overflow
                         the card instead of scrolling inside it. -->
                    <fieldset
                        v-if="selectedBranchId !== null"
                        class="flex min-w-0 flex-col gap-2"
                    >
                        <legend
                            class="mb-1 flex items-center gap-1.5 text-sm font-medium text-[var(--color-ink-muted)]"
                        >
                            <CalendarDays class="size-4" aria-hidden="true" />
                            เลือกวัน
                        </legend>
                        <div
                            class="-mx-1 flex min-w-0 gap-2 overflow-x-auto px-1 pb-1"
                        >
                            <button
                                v-for="day in dayOptions"
                                :key="day.key"
                                type="button"
                                class="member-tap flex w-20 shrink-0 flex-col items-center gap-0.5 rounded-2xl border px-3 py-2 text-center"
                                :class="
                                    selectedDate === day.key
                                        ? 'border-[var(--color-primary-strong)] bg-[var(--color-member-accent)] text-[var(--color-ink)]'
                                        : 'border-[var(--color-member-border)] bg-[var(--color-surface)] text-[var(--color-ink)]'
                                "
                                :aria-pressed="selectedDate === day.key"
                                @click="chooseDate(day.key)"
                            >
                                <span
                                    class="whitespace-nowrap text-xs text-[var(--color-ink-muted)]"
                                >
                                    {{ weekdayLabel(day.iso) }}
                                </span>
                                <span
                                    class="whitespace-nowrap font-heading text-sm font-semibold tabular-nums"
                                >
                                    {{ dayChipLabel(day.iso) }}
                                </span>
                            </button>
                        </div>
                    </fieldset>

                    <!-- Step 3 — Slots -->
                    <fieldset
                        v-if="selectedBranchId !== null && selectedDate"
                        class="flex flex-col gap-2"
                    >
                        <legend
                            class="mb-1 text-sm font-medium text-[var(--color-ink-muted)]"
                        >
                            เลือกเวลา
                        </legend>

                        <!-- Loading -->
                        <p
                            v-if="slotsLoading"
                            class="rounded-2xl bg-[var(--color-disabled-bg)] px-4 py-6 text-center text-sm text-[var(--color-ink-muted)]"
                            role="status"
                        >
                            กำลังโหลดรอบเวลา…
                        </p>

                        <!-- Fetch error -->
                        <p
                            v-else-if="slotsError"
                            class="rounded-2xl bg-[var(--color-warning-surface)] px-4 py-6 text-center text-sm text-[var(--color-ink)]"
                            role="alert"
                        >
                            โหลดรอบเวลาไม่สำเร็จ ลองเลือกวันอีกครั้ง
                        </p>

                        <!-- Empty (full / no rounds) -->
                        <p
                            v-else-if="slots.length === 0"
                            class="rounded-2xl bg-[var(--color-disabled-bg)] px-4 py-6 text-center text-sm text-[var(--color-ink-muted)]"
                        >
                            วันนี้เต็มหรือไม่มีรอบว่าง ลองเลือกวันอื่น
                        </p>

                        <!-- Slot chips -->
                        <div
                            v-else
                            class="grid grid-cols-2 gap-2 sm:grid-cols-3"
                        >
                            <button
                                v-for="slot in slots"
                                :key="slot.start"
                                type="button"
                                class="member-tap flex flex-col items-center gap-0.5 rounded-2xl border px-3 py-2.5 text-center"
                                :class="[
                                    slot.is_full
                                        ? 'cursor-not-allowed border-[var(--color-member-border)] bg-[var(--color-disabled-bg)] text-[var(--color-disabled-text)]'
                                        : selectedSlot?.start === slot.start
                                          ? 'border-[var(--color-primary-strong)] bg-[var(--color-member-accent)] text-[var(--color-ink)]'
                                          : 'border-[var(--color-member-border)] bg-[var(--color-surface)] text-[var(--color-ink)]',
                                ]"
                                :disabled="slot.is_full"
                                :aria-pressed="
                                    selectedSlot?.start === slot.start
                                "
                                @click="chooseSlot(slot)"
                            >
                                <span
                                    class="font-heading text-sm font-semibold tabular-nums"
                                >
                                    {{
                                        formatThaiTimeRange(
                                            slot.start,
                                            slot.end,
                                        )
                                    }}
                                </span>
                                <span class="text-xs">
                                    {{
                                        slot.is_full
                                            ? 'เต็ม'
                                            : `เหลือ ${slot.remaining} คิว`
                                    }}
                                </span>
                            </button>
                        </div>
                    </fieldset>

                    <!-- Step 4 — Service + note + confirm -->
                    <fieldset
                        v-if="selectedSlot"
                        class="flex flex-col gap-3 border-t border-[var(--color-member-border)] pt-4"
                    >
                        <legend
                            class="mb-1 flex items-center gap-1.5 text-sm font-medium text-[var(--color-ink-muted)]"
                        >
                            <Sparkles class="size-4" aria-hidden="true" />
                            เลือกบริการ
                        </legend>

                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="service in services"
                                :key="service.item_code"
                                type="button"
                                class="member-tap rounded-full border px-4 py-2 text-sm"
                                :class="
                                    selectedService === service.item_code
                                        ? 'border-[var(--color-primary-strong)] bg-[var(--color-member-accent)] text-[var(--color-ink)]'
                                        : 'border-[var(--color-member-border)] bg-[var(--color-surface)] text-[var(--color-ink)]'
                                "
                                :aria-pressed="
                                    selectedService === service.item_code
                                "
                                @click="selectedService = service.item_code"
                            >
                                {{ service.item_name }}
                            </button>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label
                                for="member-booking-note"
                                class="text-sm font-medium text-[var(--color-ink-muted)]"
                            >
                                หมายเหตุ (ไม่บังคับ)
                            </label>
                            <textarea
                                id="member-booking-note"
                                v-model="note"
                                rows="2"
                                maxlength="255"
                                placeholder="เช่น ขอพนักงานท่านเดิม"
                                class="w-full rounded-2xl border border-[var(--color-member-border)] bg-[var(--color-surface)] px-4 py-3 text-sm text-[var(--color-ink)] outline-none focus:border-[var(--color-primary-strong)] focus:ring-2 focus:ring-[var(--color-focus)]"
                            />
                        </div>

                        <button
                            type="button"
                            class="member-cta mt-1 w-full rounded-2xl px-6 py-3 text-sm font-semibold"
                            :class="
                                canConfirm
                                    ? 'bg-[var(--color-primary-strong)] text-white'
                                    : 'cursor-not-allowed bg-[var(--color-disabled-bg)] text-[var(--color-disabled-text)]'
                            "
                            :disabled="!canConfirm"
                            @click="confirm"
                        >
                            {{ submitting ? 'กำลังจอง…' : 'ยืนยันการจอง' }}
                        </button>
                    </fieldset>
                </template>
            </section>

            <!-- ── การจองของฉัน ───────────────────────────────────────── -->
            <section
                class="flex flex-col gap-3"
                aria-labelledby="member-my-bookings-title"
            >
                <h2
                    id="member-my-bookings-title"
                    class="member-in font-heading text-sm font-semibold text-[var(--color-ink-muted)]"
                    style="--delay: 120ms"
                >
                    การจองของฉัน
                </h2>

                <!-- Upcoming (with cancel) -->
                <div
                    class="member-in flex flex-col gap-2"
                    style="--delay: 160ms"
                >
                    <ul v-if="upcoming.length > 0" class="flex flex-col gap-2">
                        <li
                            v-for="booking in upcoming"
                            :key="booking.id"
                            class="flex flex-col gap-2 rounded-2xl border border-[var(--color-member-border)] bg-[var(--color-surface)] p-4 shadow-[var(--shadow-soft)]"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 flex-col gap-0.5">
                                    <span
                                        class="truncate font-medium text-[var(--color-ink)]"
                                    >
                                        {{ booking.item_name }}
                                    </span>
                                    <span
                                        v-if="booking.branch_name"
                                        class="truncate text-xs text-[var(--color-ink-muted)]"
                                    >
                                        {{ booking.branch_name }}
                                    </span>
                                </div>
                                <MemberBookingStatusBadge
                                    :status="booking.status"
                                />
                            </div>

                            <div
                                class="flex items-center gap-2 text-sm text-[var(--color-ink)]"
                            >
                                <CalendarDays
                                    class="size-4 shrink-0 text-[var(--color-ink-muted)]"
                                    aria-hidden="true"
                                />
                                <span class="tabular-nums">
                                    {{
                                        formatThaiDate(booking.scheduled_start)
                                    }}
                                    ·
                                    {{
                                        formatThaiTimeRange(
                                            booking.scheduled_start,
                                            booking.scheduled_end,
                                        )
                                    }}
                                </span>
                            </div>

                            <p
                                v-if="booking.note"
                                class="text-xs text-[var(--color-ink-muted)]"
                            >
                                {{ booking.note }}
                            </p>

                            <button
                                v-if="isCancellable(booking.status)"
                                type="button"
                                class="member-tap mt-1 self-start rounded-full border border-[var(--color-member-border)] bg-[var(--color-surface)] px-4 py-2 text-xs font-medium text-[var(--color-ink)]"
                                :disabled="cancellingId === booking.id"
                                @click="cancel(booking)"
                            >
                                {{
                                    cancellingId === booking.id
                                        ? 'กำลังยกเลิก…'
                                        : 'ยกเลิกการจอง'
                                }}
                            </button>
                        </li>
                    </ul>

                    <p
                        v-else
                        class="rounded-2xl border border-[var(--color-member-border)] bg-[var(--color-surface)] px-4 py-8 text-center text-sm text-[var(--color-ink-muted)] shadow-[var(--shadow-soft)]"
                    >
                        ยังไม่มีการจองที่กำลังจะถึง
                    </p>
                </div>

                <!-- Recent (history, no actions) -->
                <template v-if="recent.length > 0">
                    <h3
                        class="member-in mt-2 font-heading text-xs font-semibold text-[var(--color-ink-muted)]"
                        style="--delay: 200ms"
                    >
                        ที่ผ่านมา
                    </h3>
                    <ul
                        class="member-in flex flex-col rounded-2xl border border-[var(--color-member-border)] bg-[var(--color-surface)] p-2 shadow-[var(--shadow-soft)]"
                        style="--delay: 240ms"
                    >
                        <li
                            v-for="(booking, i) in recent"
                            :key="booking.id"
                            class="flex items-center gap-3 px-3 py-3"
                            :class="
                                i > 0
                                    ? 'border-t border-[var(--color-member-border)]'
                                    : ''
                            "
                        >
                            <div class="flex min-w-0 flex-1 flex-col">
                                <span
                                    class="truncate text-sm text-[var(--color-ink)]"
                                >
                                    {{ booking.item_name }}
                                </span>
                                <span
                                    class="text-xs text-[var(--color-ink-muted)]"
                                >
                                    <template v-if="booking.branch_name">
                                        {{ booking.branch_name }} ·
                                    </template>
                                    {{
                                        formatThaiDate(booking.scheduled_start)
                                    }}
                                    ·
                                    {{
                                        formatThaiTimeRange(
                                            booking.scheduled_start,
                                            booking.scheduled_end,
                                        )
                                    }}
                                </span>
                            </div>
                            <MemberBookingStatusBadge
                                :status="booking.status"
                            />
                        </li>
                    </ul>
                </template>
            </section>
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

/* Tappable controls (chips, day cells, cancel) — ≥44px touch target + feedback. */
.member-tap {
    min-height: 44px;
    transition:
        filter 160ms ease-out,
        transform 160ms ease-out;
}

.member-tap:hover:not(:disabled) {
    filter: brightness(0.98);
}

.member-tap:active:not(:disabled) {
    transform: translateY(1px);
}

.member-tap:focus-visible {
    outline: 2px solid var(--color-focus);
    outline-offset: 2px;
}

.member-cta {
    min-height: 44px;
    transition:
        filter 160ms ease-out,
        transform 160ms ease-out;
}

.member-cta:hover:not(:disabled) {
    filter: brightness(0.97);
}

.member-cta:active:not(:disabled) {
    transform: translateY(1px);
}

.member-cta:focus-visible {
    outline: 2px solid var(--color-focus);
    outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
    .member-in {
        animation: none;
    }

    .member-tap,
    .member-cta {
        transition: none;
    }
}
</style>
