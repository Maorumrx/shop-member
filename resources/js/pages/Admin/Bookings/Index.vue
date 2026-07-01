<script setup lang="ts">
/**
 * Admin/Bookings/Index — the dense "การจอง" day board (route `bookings.index`,
 * behind `role:owner,staff`). A branch + date picker drive a partial Inertia
 * reload; a scannable table lists that day's bookings with per-row actions.
 *
 * Branch selector: OWNER sees every branch (a Select that pushes `?branch_id=`);
 * STAFF are pinned to their branch server-side, so the control is shown read-only
 * (reflecting `filters.branch_id`) — changing it does nothing they aren't allowed
 * to do. The date picker defaults to `filters.date`.
 *
 * Actions (only on `confirmed`):
 *  - เช็คอิน → POST /bookings/{id}/check-in (triggers redemption; may fail on an
 *    insufficient balance, in which case the backend flashes an error toast and
 *    leaves the booking confirmed — nothing to handle here beyond the global toast).
 *  - ไม่มาตามนัด → POST /bookings/{id}/no-show.
 *  - ยกเลิก → DELETE /bookings/{id} (confirm dialog).
 * Terminal statuses (completed/cancelled/no_show) show their timestamp instead.
 *
 * Book-on-behalf: when this board is opened with `?member_id=&member_name=` (the
 * shortcut from a member's Show page), a "จองคิวให้สมาชิก" panel appears above
 * the table. It reuses the board's OWN `availability` slots (no members-guarded
 * fetch needed — staff can't reach that endpoint), so the staff pick a real slot
 * + service and POST straight to `bookings.store` with the pinned `member_id`.
 */
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    CalendarDays,
    CalendarPlus,
    CalendarX,
    LogIn,
    Store,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import BookingStatusBadge from '@/components/admin/BookingStatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    formatThaiDate,
    formatThaiDateTime,
    formatThaiTimeRange,
} from '@/lib/thai';
import type {
    AdminBookingRow,
    BookingBranch,
    BookingFilters,
    BookingService,
    BookingSlot,
} from '@/types/bookings';

const props = defineProps<{
    bookings: AdminBookingRow[];
    availability: BookingSlot[];
    branches: BookingBranch[];
    services: BookingService[];
    filters: BookingFilters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'การจอง', href: '/bookings' }],
    },
});

const page = usePage();
const isOwner = computed(() => page.props.auth.user.role === 'owner');

const activeBranch = computed<BookingBranch | null>(
    () => props.branches.find((b) => b.id === props.filters.branch_id) ?? null,
);

/* ── Book-on-behalf target (arrives via ?member_id=&member_name=) ────────── */
// The member's Show page links here with the member pinned; we read it from the
// URL (the backend doesn't echo it back as a prop). While set, the board shows a
// "จองคิวให้สมาชิก" panel that reuses THIS page's `availability` slots.
const behalfMemberId = ref<number | null>(null);
const behalfMemberName = ref<string | null>(null);

if (typeof window !== 'undefined') {
    const params = new URLSearchParams(window.location.search);
    const rawId = params.get('member_id');

    if (rawId !== null && rawId !== '' && Number.isFinite(Number(rawId))) {
        behalfMemberId.value = Number(rawId);
        behalfMemberName.value = params.get('member_name');
    }
}

/** Push a new branch/date to the server (partial, replace history, keep scroll). */
function applyFilters(branchId: number | null, date: string): void {
    // The availability grid is about to be replaced — drop any slot the behalf
    // panel had chosen so a stale slot can't be submitted against the new day.
    behalfSlot.value = null;

    router.get(
        '/bookings',
        {
            branch_id: branchId ?? undefined,
            date,
            // Preserve the book-on-behalf target across branch/date changes.
            member_id: behalfMemberId.value ?? undefined,
            member_name: behalfMemberName.value ?? undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['bookings', 'availability', 'filters'],
        },
    );
}

function onBranchChange(value: string): void {
    applyFilters(value === '' ? null : Number(value), props.filters.date);
}

function onDateChange(value: string): void {
    if (!value) {
        return;
    }

    applyFilters(props.filters.branch_id, value);
}

/* ── Row actions ────────────────────────────────────────────────────────── */
const busyId = ref<number | null>(null);

function checkIn(booking: AdminBookingRow): void {
    if (busyId.value !== null) {
        return;
    }

    busyId.value = booking.id;
    router.post(
        `/bookings/${booking.id}/check-in`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busyId.value = null;
            },
        },
    );
}

function noShow(booking: AdminBookingRow): void {
    if (busyId.value !== null) {
        return;
    }

    busyId.value = booking.id;
    router.post(
        `/bookings/${booking.id}/no-show`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busyId.value = null;
            },
        },
    );
}

/* ── Cancel (confirm dialog) ────────────────────────────────────────────── */
const cancelTarget = ref<AdminBookingRow | null>(null);
const cancelForm = useForm({});

function confirmCancel(): void {
    if (!cancelTarget.value) {
        return;
    }

    cancelForm.delete(`/bookings/${cancelTarget.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelTarget.value = null;
        },
    });
}

/** Terminal-state timestamp shown in place of actions. */
function terminalTimestamp(booking: AdminBookingRow): string {
    if (booking.status === 'completed') {
        return formatThaiDateTime(booking.completed_at);
    }

    if (booking.status === 'checked_in') {
        return formatThaiDateTime(booking.checked_in_at);
    }

    if (booking.status === 'cancelled') {
        return formatThaiDateTime(booking.cancelled_at);
    }

    return '—';
}

const CREATED_VIA_LABEL: Record<string, string> = {
    member: 'สมาชิก',
    staff: 'พนักงาน',
};

/* ── Book on behalf (POST /bookings with member_id pinned) ──────────────── */
const behalfSlot = ref<BookingSlot | null>(null);
const behalfForm = useForm<{
    member_id: number | null;
    branch_id: number | null;
    item_code: string | null;
    scheduled_start: string | null;
    note: string | null;
}>({
    member_id: behalfMemberId.value,
    branch_id: props.filters.branch_id,
    item_code: props.services.length === 1 ? props.services[0].item_code : null,
    scheduled_start: null,
    note: '',
});

/** Local text for the note input, normalized to null on submit. */
const behalfNote = ref('');

/** Bookable (not full) slots from the board's availability, for the behalf panel. */
const behalfSlots = computed(() => props.availability);

const canBookBehalf = computed(
    () =>
        behalfMemberId.value !== null &&
        props.filters.branch_id !== null &&
        behalfForm.item_code !== null &&
        behalfSlot.value !== null &&
        !behalfSlot.value.is_full &&
        !behalfForm.processing,
);

function chooseBehalfSlot(slot: BookingSlot): void {
    if (slot.is_full) {
        return;
    }

    behalfSlot.value = slot;
}

function submitBehalf(): void {
    if (!canBookBehalf.value || behalfSlot.value === null) {
        return;
    }

    // Keep the payload in sync with the current board branch + chosen slot.
    behalfForm.member_id = behalfMemberId.value;
    behalfForm.branch_id = props.filters.branch_id;
    behalfForm.scheduled_start = behalfSlot.value.start;
    behalfForm.note =
        behalfNote.value.trim() === '' ? null : behalfNote.value.trim();

    behalfForm.post('/bookings', {
        preserveScroll: true,
        onSuccess: () => {
            // The board refreshes on redirect; reset the panel's slot/note so the
            // next behalf-booking starts clean.
            behalfSlot.value = null;
            behalfNote.value = '';
        },
    });
}
</script>

<template>
    <Head title="การจอง" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <CalendarDays class="size-5 text-muted-foreground" />
                <h1 class="font-heading text-xl font-semibold">การจอง</h1>
            </div>
        </div>

        <!-- Branch + date filters -->
        <div class="flex flex-wrap items-end gap-4">
            <div class="flex flex-col gap-1.5">
                <label
                    for="booking-branch"
                    class="flex items-center gap-1.5 text-sm font-medium text-muted-foreground"
                >
                    <Store class="size-4" />
                    สาขา
                </label>

                <!-- Owner: switchable Select. -->
                <Select
                    v-if="isOwner"
                    :model-value="
                        props.filters.branch_id === null
                            ? ''
                            : String(props.filters.branch_id)
                    "
                    @update:model-value="onBranchChange($event as string)"
                >
                    <SelectTrigger id="booking-branch" class="w-56">
                        <SelectValue placeholder="เลือกสาขา" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="branch in props.branches"
                            :key="branch.id"
                            :value="String(branch.id)"
                        >
                            {{ branch.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <!-- Staff: pinned to their branch (read-only). -->
                <div
                    v-else
                    class="flex h-9 w-56 items-center rounded-md border border-border bg-muted/40 px-3 text-sm"
                >
                    {{ activeBranch?.name ?? '—' }}
                </div>
            </div>

            <div class="flex flex-col gap-1.5">
                <label
                    for="booking-date"
                    class="flex items-center gap-1.5 text-sm font-medium text-muted-foreground"
                >
                    <CalendarDays class="size-4" />
                    วันที่
                </label>
                <Input
                    id="booking-date"
                    type="date"
                    class="w-44"
                    :model-value="props.filters.date"
                    @update:model-value="onDateChange(String($event))"
                />
            </div>

            <p class="pb-1.5 text-sm text-muted-foreground">
                {{ formatThaiDate(props.filters.date) }}
            </p>
        </div>

        <!-- Book on behalf (only when arriving from a member's Show page) -->
        <Card v-if="behalfMemberId !== null" class="border-primary/40">
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <CalendarPlus class="size-4 text-muted-foreground" />
                    จองคิวให้สมาชิก
                    <span class="font-normal text-muted-foreground">
                        — {{ behalfMemberName ?? `#${behalfMemberId}` }}
                    </span>
                </CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <p class="text-sm text-muted-foreground">
                    เลือกสาขา/วันที่ด้านบน
                    แล้วเลือกรอบเวลาและบริการเพื่อจองแทนสมาชิก
                </p>

                <!-- Service -->
                <div class="grid max-w-sm gap-2">
                    <Label for="behalf-service">บริการ</Label>
                    <Select
                        :model-value="behalfForm.item_code ?? ''"
                        @update:model-value="
                            behalfForm.item_code =
                                $event === '' ? null : ($event as string)
                        "
                    >
                        <SelectTrigger id="behalf-service" class="w-full">
                            <SelectValue placeholder="เลือกบริการ" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="service in props.services"
                                :key="service.item_code"
                                :value="service.item_code"
                            >
                                {{ service.item_name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <!-- Slots (from the board's own availability) -->
                <div class="grid gap-2">
                    <Label>รอบเวลา</Label>
                    <p
                        v-if="behalfSlots.length === 0"
                        class="rounded-lg border border-border bg-muted/40 px-4 py-6 text-center text-sm text-muted-foreground"
                    >
                        วันนี้เต็มหรือไม่มีรอบว่าง เลือกวันอื่น
                    </p>
                    <div v-else class="flex flex-wrap gap-2">
                        <button
                            v-for="slot in behalfSlots"
                            :key="slot.start"
                            type="button"
                            class="flex flex-col items-center gap-0.5 rounded-lg border px-3 py-2 text-center text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed"
                            :class="[
                                slot.is_full
                                    ? 'border-border bg-muted text-muted-foreground'
                                    : behalfSlot?.start === slot.start
                                      ? 'border-primary bg-primary/10 text-foreground'
                                      : 'border-border bg-background text-foreground hover:bg-muted/60',
                            ]"
                            :disabled="slot.is_full"
                            :aria-pressed="behalfSlot?.start === slot.start"
                            @click="chooseBehalfSlot(slot)"
                        >
                            <span
                                class="font-heading font-semibold tabular-nums"
                            >
                                {{ formatThaiTimeRange(slot.start, slot.end) }}
                            </span>
                            <span class="text-xs">
                                {{
                                    slot.is_full
                                        ? 'เต็ม'
                                        : `เหลือ ${slot.remaining}`
                                }}
                            </span>
                        </button>
                    </div>
                </div>

                <!-- Note -->
                <div class="grid max-w-sm gap-2">
                    <Label for="behalf-note">หมายเหตุ (ไม่บังคับ)</Label>
                    <Input
                        id="behalf-note"
                        v-model="behalfNote"
                        maxlength="500"
                        placeholder="เช่น ขอพนักงานท่านเดิม"
                    />
                </div>

                <div class="flex items-center gap-2">
                    <Button
                        type="button"
                        :disabled="!canBookBehalf"
                        @click="submitBehalf"
                    >
                        <CalendarPlus />
                        ยืนยันการจอง
                    </Button>
                    <span
                        v-if="props.filters.branch_id === null"
                        class="text-xs text-destructive"
                    >
                        เลือกสาขาก่อน
                    </span>
                </div>
            </CardContent>
        </Card>

        <!-- Day board -->
        <div class="overflow-x-auto rounded-xl border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">เวลา</th>
                        <th class="px-4 py-3 font-medium">สมาชิก</th>
                        <th class="px-4 py-3 font-medium">บริการ</th>
                        <th class="px-4 py-3 font-medium">สถานะ</th>
                        <th class="px-4 py-3 font-medium">ช่องทาง</th>
                        <th class="px-4 py-3 text-right font-medium">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="booking in props.bookings"
                        :key="booking.id"
                        class="border-t border-border align-top"
                    >
                        <td
                            class="px-4 py-3 font-heading font-semibold whitespace-nowrap tabular-nums"
                        >
                            {{
                                formatThaiTimeRange(
                                    booking.scheduled_start,
                                    booking.scheduled_end,
                                )
                            }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span
                                    class="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                                >
                                    <UserRound class="size-4" />
                                </span>
                                <span class="font-medium">
                                    {{ booking.member_name ?? '—' }}
                                </span>
                            </div>
                            <p
                                v-if="booking.note"
                                class="mt-1 max-w-56 truncate text-xs text-muted-foreground"
                                :title="booking.note"
                            >
                                {{ booking.note }}
                            </p>
                        </td>
                        <td class="px-4 py-3">{{ booking.item_name }}</td>
                        <td class="px-4 py-3">
                            <BookingStatusBadge :status="booking.status" />
                        </td>
                        <td class="px-4 py-3">
                            <Badge variant="secondary">
                                {{
                                    CREATED_VIA_LABEL[booking.created_via] ??
                                    booking.created_via
                                }}
                            </Badge>
                            <p
                                v-if="booking.created_by_name"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ booking.created_by_name }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <!-- Actionable (confirmed) -->
                            <div
                                v-if="booking.status === 'confirmed'"
                                class="flex items-center justify-end gap-2"
                            >
                                <Button
                                    size="sm"
                                    :disabled="busyId !== null"
                                    @click="checkIn(booking)"
                                >
                                    <LogIn />
                                    เช็คอิน
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="busyId !== null"
                                    @click="noShow(booking)"
                                >
                                    <CalendarX />
                                    ไม่มาตามนัด
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="text-destructive hover:text-destructive"
                                    :disabled="busyId !== null"
                                    @click="cancelTarget = booking"
                                >
                                    ยกเลิก
                                </Button>
                            </div>

                            <!-- Checked-in: still cancellable? No — only the timestamp. -->
                            <div
                                v-else
                                class="flex items-center justify-end text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                            >
                                {{ terminalTimestamp(booking) }}
                            </div>
                        </td>
                    </tr>

                    <tr v-if="props.bookings.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            ยังไม่มีการจองในวันนี้
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Cancel confirmation -->
    <Dialog
        :open="cancelTarget !== null"
        @update:open="(open) => !open && (cancelTarget = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>ยกเลิกการจอง</DialogTitle>
                <DialogDescription>
                    ต้องการยกเลิกการจองของ “{{
                        cancelTarget?.member_name ?? '—'
                    }}” ({{
                        formatThaiTimeRange(
                            cancelTarget?.scheduled_start ?? null,
                            cancelTarget?.scheduled_end ?? null,
                        )
                    }}) ใช่หรือไม่?
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    @click="cancelTarget = null"
                >
                    ปิด
                </Button>
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="cancelForm.processing"
                    @click="confirmCancel"
                >
                    ยกเลิกการจอง
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
