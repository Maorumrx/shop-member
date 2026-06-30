<script setup lang="ts">
/**
 * Admin/Members/Show — member detail + sell flow (architecture.md §3.3, §3.5).
 *
 * Three stacked surfaces:
 *  1. Header card — name/phone/email + LINE-linked & active badges, plus an
 *     "แก้ไข" Dialog (PUT /members/{id}, mirrors the Index create/edit form).
 *  2. Balance summary — the aggregate `balanceByType` rows ("นวด: 18 ครั้ง").
 *  3. Lots list — each `member_packages` row (newest first) with purchased/expiry
 *     dates, price paid, a status badge, and its entitlements (remaining/total).
 *
 * Sell panel: pick an active package (Select), the price_paid input AUTO-FILLS
 * that package's list price but stays editable (override). On a successful sale
 * the controller redirects back to Show, so balance + lots refresh on their own.
 * `is_active` is always coerced to a real boolean in the edit payload.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    History,
    Pencil,
    Scissors,
    ShoppingCart,
    UserRound,
    Wallet,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { formatBaht } from '@/lib/money';
import type {
    ActivePackageOption,
    BalanceLine,
    EntitlementStatus,
    HistoryReason,
    HistoryRow,
    MemberDetail,
    RedemptionResult,
} from '@/types/members';

const props = defineProps<{
    member: MemberDetail;
    balanceByType: BalanceLine[];
    activePackages: ActivePackageOption[];
    history: HistoryRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'สมาชิก', href: '/members' },
            { title: 'รายละเอียด', href: '#' },
        ],
    },
});

/** Date-only Thai render; falls back to the "no expiry" caller's own label. */
function formatDate(value: string | null): string | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('th-TH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    }).format(date);
}

/** Date + time render for the history rows (a ledger entry is a point in time). */
function formatDateTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('th-TH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

/** Signed render for a ledger delta, e.g. `-1`, `+2` (zero stays bare). */
function formatDelta(delta: number): string {
    if (delta > 0) {
        return `+${delta}`;
    }

    return String(delta);
}

/** Status badge styling (active = default, terminal states = muted/destructive). */
const STATUS_LABEL: Record<EntitlementStatus, string> = {
    active: 'ใช้งานได้',
    expired: 'หมดอายุ',
    used_up: 'ใช้ครบแล้ว',
};

function statusVariant(
    status: EntitlementStatus,
): 'default' | 'secondary' | 'destructive' {
    if (status === 'active') {
        return 'default';
    }

    return status === 'expired' ? 'destructive' : 'secondary';
}

/* ── History (ledger) reason styling ────────────────────────────────────── */
const REASON_LABEL: Record<string, string> = {
    redeem: 'ตัดสิทธิ์',
    expire: 'หมดอายุ',
    refund: 'คืนสิทธิ์',
};

/** Falls back to the raw reason string for any backend-added reason. */
function reasonLabel(reason: HistoryReason): string {
    return REASON_LABEL[reason] ?? reason;
}

function reasonVariant(
    reason: HistoryReason,
): 'default' | 'secondary' | 'destructive' {
    if (reason === 'redeem') {
        return 'destructive';
    }

    if (reason === 'refund') {
        return 'default';
    }

    return 'secondary';
}

/* ── Edit member dialog (PUT /members/{id}) ─────────────────────────────── */
const editOpen = ref(false);

const editForm = useForm<{
    name: string;
    phone: string;
    email: string;
    is_active: boolean;
}>({
    name: props.member.name,
    phone: props.member.phone ?? '',
    email: props.member.email ?? '',
    is_active: props.member.is_active,
});

function openEdit(): void {
    editForm.clearErrors();
    editForm.name = props.member.name;
    editForm.phone = props.member.phone ?? '';
    editForm.email = props.member.email ?? '';
    editForm.is_active = props.member.is_active;
    editOpen.value = true;
}

function submitEdit(): void {
    editForm.put(`/members/${props.member.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;
        },
    });
}

/* ── Sell panel (POST /members/{id}/purchases) ──────────────────────────── */
const sellForm = useForm<{
    package_id: number | null;
    price_paid: string;
}>({
    package_id: null,
    price_paid: '',
});

const selectedPackage = computed<ActivePackageOption | null>(() => {
    if (sellForm.package_id === null) {
        return null;
    }

    return (
        props.activePackages.find((p) => p.id === sellForm.package_id) ?? null
    );
});

/**
 * Auto-fill price_paid with the selected package's list price (editable). We
 * normalize the decimal string/number to a 2dp string so the input shows a clean
 * default; the operator can override before selling.
 */
function onPackageChange(value: string): void {
    sellForm.package_id = value === '' ? null : Number(value);
}

watch(selectedPackage, (pkg) => {
    sellForm.price_paid = pkg == null ? '' : String(Number(pkg.price));
});

function sell(): void {
    sellForm.post(`/members/${props.member.id}/purchases`, {
        preserveScroll: true,
        onSuccess: () => {
            // Sale redirects back to Show with fresh balance/lots; just clear the
            // panel so the operator can start the next sale cleanly.
            sellForm.reset();
        },
    });
}

/* ── Redeem (POST /members/{id}/redemptions) ────────────────────────────── */
/**
 * One-click ตัดสิทธิ์ per balance row, with an optional qty stepper (default 1,
 * min 1, max = that row's remaining). We post a transient `useForm` per click so
 * `processing` can be tracked PER item_code — a fresh form is built each call,
 * but the in-flight form is stashed in `redeemingCode` so only the row being cut
 * shows a busy/disabled state (other rows stay clickable). On success the
 * controller redirects back to Show, refreshing balances + history (and the
 * global success toast fires from the flashed `toast` payload).
 */
const redeemQty = ref<Record<string, number>>({});
const redeemingCode = ref<string | null>(null);

/** Clamp the per-row qty into [1, remaining] (called on input + before submit). */
function clampQty(line: BalanceLine): number {
    const raw = redeemQty.value[line.item_code] ?? 1;
    const max = Math.max(line.remaining, 1);
    const next = Math.min(Math.max(Math.trunc(raw) || 1, 1), max);
    redeemQty.value[line.item_code] = next;

    return next;
}

function redeem(line: BalanceLine): void {
    if (line.remaining < 1 || redeemingCode.value !== null) {
        return;
    }

    const qty = clampQty(line);
    redeemingCode.value = line.item_code;

    const redeemForm = useForm<{ item_code: string; qty: number }>({
        item_code: line.item_code,
        qty,
    });

    redeemForm.post(`/members/${props.member.id}/redemptions`, {
        preserveScroll: true,
        onSuccess: () => {
            // Reset the stepper for this row; balances/history come back fresh.
            redeemQty.value[line.item_code] = 1;
        },
        onFinish: () => {
            redeemingCode.value = null;
        },
    });
}

/* ── Optional: surface the detailed redemption result ───────────────────── */
/**
 * The controller flashes a detailed `redemption` result (what was actually
 * deducted, including coupled add-ons). We mirror flashToast.ts — subscribe to
 * the Inertia `flash` event and read `event.detail.flash.redemption` — and build
 * a transient, human line ("ตัดนวด 1 (เหลือ 9) + ประคบ 1 (คู่)") shown above the
 * history. This listener is additive to the global toast listener (router.on
 * supports multiple subscribers); we tear it down on unmount. Purely polish — if
 * the flash is absent we just rely on the success toast + refreshed data.
 */
const lastRedemption = ref<string | null>(null);
let stopFlashListener: (() => void) | null = null;

function describeRedemption(result: RedemptionResult): string {
    return result.movements
        .map((m) => {
            const name = m.item_name ?? m.item_code;
            const coupled = m.was_coupled ? ' (คู่)' : '';
            return `${name} ${m.taken} (เหลือ ${m.remaining_after})${coupled}`;
        })
        .join(' + ');
}

onMounted(() => {
    stopFlashListener = router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const result = flash?.redemption as RedemptionResult | undefined;

        if (!result || result.movements.length === 0) {
            return;
        }

        lastRedemption.value = `ตัด ${describeRedemption(result)}`;
    });
});

onUnmounted(() => {
    stopFlashListener?.();
});
</script>

<template>
    <Head :title="props.member.name" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <!-- Header card -->
        <Card>
            <CardHeader>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"
                        >
                            <UserRound class="size-6" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <CardTitle class="text-xl">
                                {{ props.member.name }}
                            </CardTitle>
                            <div
                                class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground"
                            >
                                <span v-if="props.member.phone">
                                    {{ props.member.phone }}
                                </span>
                                <span v-if="props.member.email">
                                    {{ props.member.email }}
                                </span>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <Badge
                                    :variant="
                                        props.member.line_user_id
                                            ? 'default'
                                            : 'secondary'
                                    "
                                >
                                    {{
                                        props.member.line_user_id
                                            ? 'เชื่อม LINE แล้ว'
                                            : 'ยังไม่เชื่อม LINE'
                                    }}
                                </Badge>
                                <Badge
                                    :variant="
                                        props.member.is_active
                                            ? 'default'
                                            : 'secondary'
                                    "
                                >
                                    {{
                                        props.member.is_active
                                            ? 'เปิดใช้งาน'
                                            : 'ปิดใช้งาน'
                                    }}
                                </Badge>
                            </div>
                        </div>
                    </div>
                    <Button variant="outline" size="sm" @click="openEdit">
                        <Pencil />
                        แก้ไข
                    </Button>
                </div>
            </CardHeader>
        </Card>

        <!-- Balance summary -->
        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <Wallet class="size-4 text-muted-foreground" />
                    สิทธิ์คงเหลือ
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div
                    v-if="props.balanceByType.length > 0"
                    class="flex flex-wrap gap-3"
                >
                    <div
                        v-for="line in props.balanceByType"
                        :key="line.item_code"
                        class="flex flex-col gap-3 rounded-lg border border-border bg-muted/30 px-4 py-3"
                    >
                        <div>
                            <p class="text-sm text-muted-foreground">
                                {{ line.item_name }}
                            </p>
                            <p
                                class="font-heading text-2xl font-bold tabular-nums"
                            >
                                {{ line.remaining }} ครั้ง
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <Input
                                :id="`redeem-qty-${line.item_code}`"
                                type="number"
                                min="1"
                                :max="line.remaining"
                                class="h-8 w-16 tabular-nums"
                                :model-value="redeemQty[line.item_code] ?? 1"
                                :disabled="line.remaining < 1"
                                :aria-label="`จำนวนที่จะตัด — ${line.item_name}`"
                                @update:model-value="
                                    redeemQty[line.item_code] = Number($event);
                                    clampQty(line);
                                "
                            />
                            <Button
                                size="sm"
                                variant="destructive"
                                :disabled="
                                    line.remaining < 1 || redeemingCode !== null
                                "
                                @click="redeem(line)"
                            >
                                <Scissors />
                                ตัด
                            </Button>
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    ยังไม่มีสิทธิ์คงเหลือ
                </p>
            </CardContent>
        </Card>

        <!-- Sell panel -->
        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <ShoppingCart class="size-4 text-muted-foreground" />
                    ขายแพ็คเกจ
                </CardTitle>
            </CardHeader>
            <CardContent>
                <form
                    class="flex flex-col gap-4 sm:flex-row sm:items-start"
                    @submit.prevent="sell"
                >
                    <div class="grid flex-1 gap-2">
                        <Label for="sell-package">แพ็คเกจ</Label>
                        <Select
                            :model-value="
                                sellForm.package_id === null
                                    ? ''
                                    : String(sellForm.package_id)
                            "
                            @update:model-value="
                                onPackageChange($event as string)
                            "
                        >
                            <SelectTrigger id="sell-package" class="w-full">
                                <SelectValue placeholder="เลือกแพ็คเกจ" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="pkg in props.activePackages"
                                    :key="pkg.id"
                                    :value="String(pkg.id)"
                                >
                                    {{ pkg.name }} — {{ formatBaht(pkg.price) }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="sellForm.errors.package_id" />
                    </div>

                    <div class="grid gap-2 sm:w-48">
                        <Label for="sell-price">ราคาที่ชำระ (บาท)</Label>
                        <Input
                            id="sell-price"
                            v-model="sellForm.price_paid"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="0.00"
                        />
                        <p class="text-xs text-muted-foreground">
                            เติมราคาแพ็คเกจอัตโนมัติ แก้ไขได้
                        </p>
                        <InputError :message="sellForm.errors.price_paid" />
                    </div>

                    <div class="flex sm:pt-7">
                        <Button
                            type="submit"
                            :disabled="
                                sellForm.processing ||
                                sellForm.package_id === null
                            "
                        >
                            <ShoppingCart />
                            ขาย
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <!-- Lots list -->
        <Card>
            <CardHeader>
                <CardTitle class="text-base">แพ็คเกจที่ซื้อ</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <div
                    v-for="lot in props.member.member_packages"
                    :key="lot.id"
                    class="rounded-lg border border-border p-4"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2"
                    >
                        <div class="flex flex-col gap-1 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">
                                    ซื้อเมื่อ
                                    {{ formatDate(lot.purchased_at) ?? '—' }}
                                </span>
                                <Badge :variant="statusVariant(lot.status)">
                                    {{ STATUS_LABEL[lot.status] }}
                                </Badge>
                            </div>
                            <p class="text-muted-foreground">
                                หมดอายุ:
                                <span v-if="lot.expires_at">
                                    {{ formatDate(lot.expires_at) }}
                                </span>
                                <span v-else>ไม่หมดอายุ</span>
                            </p>
                        </div>
                        <p
                            class="font-heading text-base font-bold tabular-nums"
                        >
                            {{ formatBaht(lot.price_paid) }}
                        </p>
                    </div>

                    <ul
                        class="mt-3 flex flex-col gap-2 border-t border-border pt-3"
                    >
                        <li
                            v-for="ent in lot.entitlements"
                            :key="ent.id"
                            class="flex items-center justify-between gap-4 text-sm"
                        >
                            <div class="flex items-center gap-2">
                                <span>{{ ent.item_name }}</span>
                                <Badge
                                    :variant="statusVariant(ent.status)"
                                    class="text-xs"
                                >
                                    {{ STATUS_LABEL[ent.status] }}
                                </Badge>
                            </div>
                            <span
                                class="font-heading font-semibold text-muted-foreground tabular-nums"
                            >
                                {{ ent.qty_remaining }} / {{ ent.qty_total }}
                            </span>
                        </li>
                    </ul>
                </div>

                <p
                    v-if="props.member.member_packages.length === 0"
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    ยังไม่มีแพ็คเกจที่ซื้อ — ใช้แผง “ขายแพ็คเกจ” ด้านบนเพื่อขาย
                </p>
            </CardContent>
        </Card>

        <!-- Redemption / ledger history -->
        <Card>
            <CardHeader>
                <CardTitle
                    class="flex items-center gap-2 font-heading text-base"
                >
                    <History class="size-4 text-muted-foreground" />
                    ประวัติการตัดสิทธิ์
                </CardTitle>
            </CardHeader>
            <CardContent>
                <!-- Transient: what the LAST redeem actually deducted (optional). -->
                <div
                    v-if="lastRedemption"
                    class="mb-4 flex items-start gap-2 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm"
                >
                    <Scissors
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                    <span>{{ lastRedemption }}</span>
                </div>

                <div v-if="props.history.length > 0" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b border-border text-left text-xs text-muted-foreground"
                            >
                                <th class="py-2 pr-4 font-medium">เวลา</th>
                                <th class="py-2 pr-4 font-medium">รายการ</th>
                                <th class="py-2 pr-4 font-medium">เหตุผล</th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    จำนวน
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    คงเหลือ
                                </th>
                                <th class="py-2 font-medium">พนักงาน</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in props.history"
                                :key="row.id"
                                class="border-b border-border/60 last:border-0"
                            >
                                <td
                                    class="py-2 pr-4 whitespace-nowrap text-muted-foreground tabular-nums"
                                >
                                    {{ formatDateTime(row.created_at) }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ row.item_name ?? '—' }}
                                </td>
                                <td class="py-2 pr-4">
                                    <Badge
                                        :variant="reasonVariant(row.reason)"
                                        class="text-xs"
                                    >
                                        {{ reasonLabel(row.reason) }}
                                    </Badge>
                                </td>
                                <td
                                    class="py-2 pr-4 text-right font-semibold tabular-nums"
                                    :class="
                                        row.delta < 0
                                            ? 'text-destructive'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ formatDelta(row.delta) }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ row.balance_after }}
                                </td>
                                <td class="py-2 text-muted-foreground">
                                    {{ row.staff_name ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p
                    v-else
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    ยังไม่มีประวัติการตัดสิทธิ์
                </p>
            </CardContent>
        </Card>
    </div>

    <!-- Edit member dialog (PUT /members/{id}) -->
    <Dialog v-model:open="editOpen">
        <DialogContent>
            <form @submit.prevent="submitEdit">
                <DialogHeader>
                    <DialogTitle>แก้ไขสมาชิก</DialogTitle>
                    <DialogDescription>
                        แก้ไขชื่อ เบอร์โทร อีเมล และสถานะการใช้งาน
                        ปิดใช้งานแทนการลบ
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label for="edit-name">ชื่อ</Label>
                        <Input
                            id="edit-name"
                            v-model="editForm.name"
                            autofocus
                        />
                        <InputError :message="editForm.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="edit-phone">เบอร์โทร</Label>
                        <Input
                            id="edit-phone"
                            v-model="editForm.phone"
                            type="tel"
                            inputmode="tel"
                        />
                        <InputError :message="editForm.errors.phone" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="edit-email">อีเมล</Label>
                        <Input
                            id="edit-email"
                            v-model="editForm.email"
                            type="email"
                            placeholder="ไม่บังคับ"
                        />
                        <InputError :message="editForm.errors.email" />
                    </div>

                    <div class="flex items-center gap-2">
                        <Checkbox
                            id="edit-active"
                            :model-value="editForm.is_active"
                            @update:model-value="
                                editForm.is_active = $event === true
                            "
                        />
                        <Label for="edit-active">เปิดใช้งาน</Label>
                    </div>
                    <InputError :message="editForm.errors.is_active" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="editOpen = false"
                    >
                        ยกเลิก
                    </Button>
                    <Button type="submit" :disabled="editForm.processing">
                        บันทึก
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
