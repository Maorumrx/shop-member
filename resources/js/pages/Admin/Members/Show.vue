<script setup lang="ts">
/**
 * Admin/Members/Show — member detail + wallet flow (credit-wallet reframe).
 *
 * Stacked surfaces:
 *  1. Header card — name/phone/email + LINE-linked & active badges, an "แก้ไข"
 *     Dialog (PUT /members/{id}), and a "จองคิวให้สมาชิก" shortcut.
 *  2. LINE linking — generate a one-off claim code (unchanged).
 *  3. Balance hero — the single spendable ฿ figure.
 *  4. Wallet actions — SELL CREDIT (top-up preset OR custom paid + bonus),
 *     CHARGE (pick a priced service), REFUND (amount + note), and (owner-only)
 *     ADJUST (signed delta + note). Each is its own Inertia form so a 422 domain
 *     failure (keyed item_code / amount / delta) renders inline.
 *  5. Credit lots — each active lot's paid/bonus/total remaining + near-expiry.
 *  6. Wallet history — every movement (topup/bonus/debit/refund/expire/adjust)
 *     with a signed ฿ delta, running balance, and the acting staff.
 *
 * Every mutation redirects back to Show, so the balance/lots/history refresh on
 * their own; the global toast fires from the flashed `toast`. `walletResult` is
 * flashed on charge/refund/adjust and surfaced as a small transient line.
 */
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowDownCircle,
    CalendarPlus,
    History,
    Link2,
    Pencil,
    RotateCcw,
    Scissors,
    Settings2,
    ShoppingCart,
    UserRound,
    Wallet,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
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
import { formatBaht, formatSignedBaht } from '@/lib/money';
import { formatThaiDate, formatThaiDateTime, reasonLabel } from '@/lib/thai';
import type {
    CreditSource,
    HistoryReason,
    LinkCode,
    MemberDetail,
    ServiceOption,
    TopupOfferOption,
    WalletHistoryRow,
    WalletLot,
    WalletResult,
} from '@/types/members';

const props = defineProps<{
    member: MemberDetail;
    balance: string;
    lots: WalletLot[];
    topupOffers: TopupOfferOption[];
    services: ServiceOption[];
    history: WalletHistoryRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'สมาชิก', href: '/members' },
            { title: 'รายละเอียด', href: '#' },
        ],
    },
});

const page = usePage();
/** ADJUST is owner-only (route `role:owner`); hide the control for staff. */
const isOwner = computed(() => page.props.auth.user.role === 'owner');

/* ── Credit-lot source labels ───────────────────────────────────────────── */
const SOURCE_LABEL: Record<CreditSource, string> = {
    topup: 'เติมเครดิต',
    adjustment: 'ปรับยอด',
};

function sourceLabel(source: CreditSource): string {
    return SOURCE_LABEL[source] ?? source;
}

/* ── History (ledger) reason styling ────────────────────────────────────── */
function reasonVariant(
    reason: HistoryReason,
): 'default' | 'secondary' | 'destructive' {
    if (reason === 'topup' || reason === 'bonus') {
        return 'default';
    }

    if (reason === 'debit') {
        return 'destructive';
    }

    // refund / expire / adjust
    return 'secondary';
}

/** Whether a signed decimal string is negative (drives the delta text color). */
function isNegative(value: string): boolean {
    return Number.parseFloat(value) < 0;
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

/* ── Sell credit (POST /members/{id}/topups) ────────────────────────────── */
/**
 * A top-up is EITHER a preset (topup_offer_id) OR a custom pair (amount_paid +
 * bonus_amount). Picking a preset chip clears the custom fields; typing a custom
 * amount deselects the preset. The server resolves the amounts (a preset wins).
 */
const sellForm = useForm<{
    topup_offer_id: number | null;
    amount_paid: string;
    bonus_amount: string;
}>({
    topup_offer_id: null,
    amount_paid: '',
    bonus_amount: '',
});

function selectOffer(offer: TopupOfferOption): void {
    sellForm.clearErrors();
    sellForm.topup_offer_id =
        sellForm.topup_offer_id === offer.id ? null : offer.id;
    sellForm.amount_paid = '';
    sellForm.bonus_amount = '';
}

/** Typing a custom amount deselects any chosen preset. */
function onCustomInput(): void {
    sellForm.topup_offer_id = null;
}

/** Live "credit the member will get" preview (preset total, else custom sum). */
const sellPreview = computed<number>(() => {
    if (sellForm.topup_offer_id !== null) {
        const offer = props.topupOffers.find(
            (o) => o.id === sellForm.topup_offer_id,
        );

        return offer ? Number(offer.amount) + Number(offer.bonus) : 0;
    }

    return Number(sellForm.amount_paid || 0) + Number(sellForm.bonus_amount || 0);
});

const canSell = computed<boolean>(
    () => !sellForm.processing && sellPreview.value > 0,
);

/**
 * The server can attach a `member` error (selling to a deactivated member) that
 * isn't one of the form's own keys, so read it off the errors bag with a cast.
 */
const sellMemberError = computed<string | undefined>(
    () => (sellForm.errors as Record<string, string | undefined>).member,
);

function sell(): void {
    // Custom path: satisfy `required_without:topup_offer_id` when the operator
    // entered only a bonus (server still guards paid>0 || bonus>0).
    if (sellForm.topup_offer_id === null && sellForm.amount_paid === '') {
        sellForm.amount_paid = '0';
    }

    sellForm.post(`/members/${props.member.id}/topups`, {
        preserveScroll: true,
        onSuccess: () => {
            sellForm.reset();
        },
    });
}

/* ── Charge a service (POST /members/{id}/wallet/charge) ─────────────────── */
const chargeForm = useForm<{ item_code: string | null }>({
    item_code: props.services.length === 1 ? props.services[0].item_code : null,
});

const selectedService = computed<ServiceOption | null>(
    () =>
        props.services.find((s) => s.item_code === chargeForm.item_code) ??
        null,
);

function charge(): void {
    if (chargeForm.item_code === null) {
        return;
    }

    chargeForm.post(`/members/${props.member.id}/wallet/charge`, {
        preserveScroll: true,
    });
}

/* ── Refund paid credit (POST /members/{id}/wallet/refund) ───────────────── */
const refundForm = useForm<{ amount: string; note: string }>({
    amount: '',
    note: '',
});

function refund(): void {
    refundForm.post(`/members/${props.member.id}/wallet/refund`, {
        preserveScroll: true,
        onSuccess: () => {
            refundForm.reset();
        },
    });
}

/* ── Owner adjust (POST /members/{id}/wallet/adjust) ─────────────────────── */
const adjustForm = useForm<{ delta: string; note: string }>({
    delta: '',
    note: '',
});

function adjust(): void {
    adjustForm.post(`/members/${props.member.id}/wallet/adjust`, {
        preserveScroll: true,
        onSuccess: () => {
            adjustForm.reset();
        },
    });
}

/* ── Book on behalf (shortcut to the admin day board) ───────────────────── */
function bookOnBehalf(): void {
    const today = new Date();
    const y = today.getFullYear();
    const m = String(today.getMonth() + 1).padStart(2, '0');
    const d = String(today.getDate()).padStart(2, '0');

    router.get('/bookings', {
        member_id: props.member.id,
        member_name: props.member.name,
        date: `${y}-${m}-${d}`,
    });
}

/* ── LINE account linking (generate claim code) ─────────────────────────── */
const isLineLinked = computed<boolean>(
    () => props.member.line_user_id !== null,
);

const linkCode = ref<LinkCode | null>(null);
const generatingCode = ref(false);

function generateLinkCode(): void {
    if (generatingCode.value || isLineLinked.value) {
        return;
    }

    generatingCode.value = true;

    router.post(
        `/members/${props.member.id}/link-code`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                generatingCode.value = false;
            },
        },
    );
}

/* ── Transient: the last wallet action result (flashed `walletResult`) ───── */
/**
 * The controller flashes a detailed `walletResult` after charge/refund/adjust. We
 * subscribe to Inertia's `flash` event (additive to the global toast listener) and
 * show a small line above the history. Purely polish — the redirect already
 * refreshed balance/lots/history.
 */
const lastResult = ref<WalletResult | null>(null);
let stopFlashListener: (() => void) | null = null;

onMounted(() => {
    stopFlashListener = router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;

        const code = flash?.linkCode as LinkCode | undefined;

        if (code?.code) {
            linkCode.value = code;
        }

        const result = flash?.walletResult as WalletResult | undefined;

        if (result) {
            lastResult.value = result;
        }
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
                    <div class="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="bookOnBehalf"
                        >
                            <CalendarPlus />
                            จองคิวให้สมาชิก
                        </Button>
                        <Button variant="outline" size="sm" @click="openEdit">
                            <Pencil />
                            แก้ไข
                        </Button>
                    </div>
                </div>
            </CardHeader>
        </Card>

        <!-- LINE account linking (generate claim code) -->
        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <Link2 class="size-4 text-muted-foreground" />
                    เชื่อมบัญชี LINE
                </CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <div
                    v-if="isLineLinked"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Badge variant="secondary">เชื่อม LINE แล้ว</Badge>
                    <span>สมาชิกนี้ผูกบัญชี LINE เรียบร้อยแล้ว</span>
                </div>

                <template v-else>
                    <p class="text-sm text-muted-foreground">
                        สร้างรหัส 6 หลักให้ลูกค้ากรอกในแอป LINE
                        เพื่อผูกเครดิตของสมาชิกนี้เข้ากับบัญชี LINE ของลูกค้า
                    </p>

                    <div
                        v-if="linkCode"
                        class="flex flex-col gap-2 rounded-lg border border-border bg-muted/30 px-4 py-4"
                    >
                        <p
                            class="font-heading text-4xl font-bold tracking-[0.3em] tabular-nums"
                        >
                            {{ linkCode.code }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            ให้ลูกค้ากรอกรหัสนี้ในแอป LINE ภายในเวลาที่กำหนด
                            (หมดอายุ
                            {{ formatThaiDateTime(linkCode.expires_at) }})
                        </p>
                        <p class="text-xs text-muted-foreground">
                            รหัสล่าสุดที่สร้างเท่านั้นที่ใช้ได้ —
                            การสร้างรหัสใหม่จะทำให้รหัสเดิมใช้ไม่ได้ทันที
                        </p>
                    </div>

                    <div>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="generatingCode"
                            @click="generateLinkCode"
                        >
                            <Link2 />
                            {{
                                linkCode
                                    ? 'สร้างรหัสใหม่'
                                    : 'สร้างรหัสเชื่อม LINE'
                            }}
                        </Button>
                    </div>
                </template>
            </CardContent>
        </Card>

        <!-- Balance hero -->
        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <Wallet class="size-4 text-muted-foreground" />
                    เครดิตคงเหลือ
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p class="font-heading text-4xl font-bold tabular-nums">
                    {{ formatBaht(props.balance) }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    ยอดเครดิตที่ใช้จ่ายได้ทั้งหมด (รวมโบนัส)
                </p>
            </CardContent>
        </Card>

        <!-- Sell credit -->
        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <ShoppingCart class="size-4 text-muted-foreground" />
                    ขายเครดิต
                </CardTitle>
            </CardHeader>
            <CardContent>
                <form class="flex flex-col gap-4" @submit.prevent="sell">
                    <!-- Preset quick-pick chips -->
                    <div
                        v-if="props.topupOffers.length > 0"
                        class="flex flex-col gap-2"
                    >
                        <Label>แพ็กเกจเติมเครดิต</Label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="offer in props.topupOffers"
                                :key="offer.id"
                                type="button"
                                class="flex flex-col items-start gap-0.5 rounded-lg border px-4 py-2 text-left text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                :class="
                                    sellForm.topup_offer_id === offer.id
                                        ? 'border-primary bg-primary/10 text-foreground'
                                        : 'border-border bg-background text-foreground hover:bg-muted/60'
                                "
                                :aria-pressed="
                                    sellForm.topup_offer_id === offer.id
                                "
                                @click="selectOffer(offer)"
                            >
                                <span class="font-medium">{{ offer.name }}</span>
                                <span class="text-xs text-muted-foreground">
                                    จ่าย {{ formatBaht(offer.amount) }}
                                    <template v-if="Number(offer.bonus) > 0">
                                        · โบนัส {{ formatBaht(offer.bonus) }}
                                    </template>
                                </span>
                            </button>
                        </div>
                        <InputError :message="sellForm.errors.topup_offer_id" />
                    </div>

                    <!-- Custom amount + bonus -->
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="sell-amount">ยอดจ่าย (บาท)</Label>
                            <Input
                                id="sell-amount"
                                v-model="sellForm.amount_paid"
                                type="number"
                                step="0.01"
                                min="0"
                                placeholder="หรือกำหนดเอง"
                                @input="onCustomInput"
                            />
                            <InputError :message="sellForm.errors.amount_paid" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="sell-bonus">โบนัส (บาท)</Label>
                            <Input
                                id="sell-bonus"
                                v-model="sellForm.bonus_amount"
                                type="number"
                                step="0.01"
                                min="0"
                                placeholder="0.00"
                                @input="onCustomInput"
                            />
                            <InputError
                                :message="sellForm.errors.bonus_amount"
                            />
                        </div>
                    </div>

                    <InputError :message="sellMemberError" />

                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <p class="text-sm text-muted-foreground">
                            เครดิตที่จะได้:
                            <span
                                class="font-heading text-base font-semibold text-foreground tabular-nums"
                            >
                                {{ formatBaht(sellPreview) }}
                            </span>
                        </p>
                        <Button type="submit" :disabled="!canSell">
                            <ShoppingCart />
                            ขายเครดิต
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <!-- Charge a service -->
        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <Scissors class="size-4 text-muted-foreground" />
                    หักเครดิต (ใช้บริการ)
                </CardTitle>
            </CardHeader>
            <CardContent>
                <form
                    class="flex flex-col gap-4 sm:flex-row sm:items-start"
                    @submit.prevent="charge"
                >
                    <div class="grid flex-1 gap-2">
                        <Label for="charge-service">บริการ</Label>
                        <Select
                            :model-value="chargeForm.item_code ?? ''"
                            @update:model-value="
                                chargeForm.item_code =
                                    $event === '' ? null : ($event as string)
                            "
                        >
                            <SelectTrigger id="charge-service" class="w-full">
                                <SelectValue placeholder="เลือกบริการ" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="service in props.services"
                                    :key="service.item_code"
                                    :value="service.item_code"
                                >
                                    {{ service.name }} —
                                    {{ formatBaht(service.price) }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p
                            v-if="selectedService"
                            class="text-xs text-muted-foreground"
                        >
                            จะหักเครดิต
                            {{ formatBaht(selectedService.price) }}
                        </p>
                        <InputError :message="chargeForm.errors.item_code" />
                    </div>

                    <div class="flex sm:pt-7">
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="
                                chargeForm.processing ||
                                chargeForm.item_code === null
                            "
                        >
                            <Scissors />
                            หักเครดิต
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <!-- Refund + Adjust -->
        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Refund paid credit -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base">
                        <RotateCcw class="size-4 text-muted-foreground" />
                        คืนเงิน
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <form class="flex flex-col gap-4" @submit.prevent="refund">
                        <div class="grid gap-2">
                            <Label for="refund-amount">จำนวนเงิน (บาท)</Label>
                            <Input
                                id="refund-amount"
                                v-model="refundForm.amount"
                                type="number"
                                step="0.01"
                                min="0"
                                placeholder="0.00"
                            />
                            <p class="text-xs text-muted-foreground">
                                คืนได้เฉพาะเงินสดที่จ่ายมา (ไม่รวมโบนัส)
                            </p>
                            <InputError :message="refundForm.errors.amount" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="refund-note">เหตุผล</Label>
                            <Input
                                id="refund-note"
                                v-model="refundForm.note"
                                maxlength="255"
                                placeholder="เช่น ลูกค้าขอคืนเงิน"
                            />
                            <InputError :message="refundForm.errors.note" />
                        </div>
                        <div class="flex justify-end">
                            <Button
                                type="submit"
                                variant="outline"
                                :disabled="refundForm.processing"
                            >
                                <RotateCcw />
                                คืนเงิน
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <!-- Owner adjust (signed) -->
            <Card v-if="isOwner">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base">
                        <Settings2 class="size-4 text-muted-foreground" />
                        ปรับยอดเครดิต
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <form class="flex flex-col gap-4" @submit.prevent="adjust">
                        <div class="grid gap-2">
                            <Label for="adjust-delta">
                                ยอดปรับ (บาท, ใส่ค่าลบเพื่อหัก)
                            </Label>
                            <Input
                                id="adjust-delta"
                                v-model="adjustForm.delta"
                                type="number"
                                step="0.01"
                                placeholder="เช่น 500 หรือ -50"
                            />
                            <p class="text-xs text-muted-foreground">
                                ค่าบวก = เพิ่มเครดิต (เป็นโบนัส) · ค่าลบ = หัก
                            </p>
                            <InputError :message="adjustForm.errors.delta" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="adjust-note">เหตุผล</Label>
                            <Input
                                id="adjust-note"
                                v-model="adjustForm.note"
                                maxlength="255"
                                placeholder="เช่น ชดเชยความผิดพลาด"
                            />
                            <InputError :message="adjustForm.errors.note" />
                        </div>
                        <div class="flex justify-end">
                            <Button
                                type="submit"
                                variant="outline"
                                :disabled="adjustForm.processing"
                            >
                                <Settings2 />
                                ปรับยอด
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>

        <!-- Credit lots -->
        <Card>
            <CardHeader>
                <CardTitle class="text-base">ล็อตเครดิต</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <div
                    v-for="lot in props.lots"
                    :key="lot.id"
                    class="rounded-lg border border-border p-4"
                    :class="
                        lot.is_near_expiry
                            ? 'border-l-4 border-l-[var(--color-warning)]'
                            : ''
                    "
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2"
                    >
                        <div class="flex flex-col gap-1 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">
                                    {{ sourceLabel(lot.source) }} ·
                                    {{ formatThaiDate(lot.purchased_at) }}
                                </span>
                                <Badge
                                    v-if="lot.is_near_expiry"
                                    variant="secondary"
                                >
                                    ใกล้หมดอายุ
                                </Badge>
                            </div>
                            <p class="text-muted-foreground">
                                หมดอายุ:
                                <span v-if="lot.expires_at">
                                    {{ formatThaiDate(lot.expires_at) }}
                                </span>
                                <span v-else>ไม่หมดอายุ</span>
                            </p>
                            <p class="text-xs text-muted-foreground">
                                เดิม: จ่าย {{ formatBaht(lot.amount_paid) }} ·
                                โบนัส {{ formatBaht(lot.bonus_amount) }}
                            </p>
                        </div>
                        <div class="text-right">
                            <p
                                class="font-heading text-base font-bold tabular-nums"
                            >
                                {{ formatBaht(lot.total_remaining) }}
                            </p>
                            <p class="text-xs text-muted-foreground">คงเหลือ</p>
                        </div>
                    </div>

                    <div
                        class="mt-3 flex flex-wrap gap-x-6 gap-y-1 border-t border-border pt-3 text-sm"
                    >
                        <span class="text-muted-foreground">
                            เงินสดคงเหลือ:
                            <span class="font-medium text-foreground tabular-nums">
                                {{ formatBaht(lot.paid_remaining) }}
                            </span>
                        </span>
                        <span class="text-muted-foreground">
                            โบนัสคงเหลือ:
                            <span class="font-medium text-foreground tabular-nums">
                                {{ formatBaht(lot.bonus_remaining) }}
                            </span>
                        </span>
                    </div>
                </div>

                <p
                    v-if="props.lots.length === 0"
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    ยังไม่มีเครดิตคงเหลือ — ใช้แผง “ขายเครดิต” ด้านบนเพื่อเติม
                </p>
            </CardContent>
        </Card>

        <!-- Wallet history -->
        <Card>
            <CardHeader>
                <CardTitle
                    class="flex items-center gap-2 font-heading text-base"
                >
                    <History class="size-4 text-muted-foreground" />
                    ประวัติเครดิต
                </CardTitle>
            </CardHeader>
            <CardContent>
                <!-- Transient: what the LAST wallet action moved (optional). -->
                <div
                    v-if="lastResult"
                    class="mb-4 flex items-start gap-2 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm"
                >
                    <ArrowDownCircle
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                    <span>
                        รายการล่าสุด
                        <span
                            class="font-heading font-semibold tabular-nums"
                            :class="
                                isNegative(lastResult.net_delta)
                                    ? 'text-destructive'
                                    : 'text-[var(--color-success)]'
                            "
                        >
                            {{ formatSignedBaht(lastResult.net_delta) }}
                        </span>
                        · คงเหลือ
                        {{ formatBaht(lastResult.balance_after) }}
                    </span>
                </div>

                <div v-if="props.history.length > 0" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b border-border text-left text-xs text-muted-foreground"
                            >
                                <th class="py-2 pr-4 font-medium">เวลา</th>
                                <th class="py-2 pr-4 font-medium">ประเภท</th>
                                <th class="py-2 pr-4 font-medium">หมายเหตุ</th>
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
                                    {{ formatThaiDateTime(row.created_at) }}
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
                                    class="py-2 pr-4 text-muted-foreground"
                                >
                                    {{ row.note ?? '—' }}
                                </td>
                                <td
                                    class="py-2 pr-4 text-right font-semibold tabular-nums"
                                    :class="
                                        isNegative(row.delta)
                                            ? 'text-destructive'
                                            : 'text-[var(--color-success)]'
                                    "
                                >
                                    {{ formatSignedBaht(row.delta) }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ formatBaht(row.balance_after) }}
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
                    ยังไม่มีประวัติเครดิต
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
