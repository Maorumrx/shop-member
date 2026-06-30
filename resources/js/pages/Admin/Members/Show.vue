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
import { Head, useForm } from '@inertiajs/vue3';
import { Pencil, ShoppingCart, UserRound, Wallet } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
    MemberDetail,
} from '@/types/members';

const props = defineProps<{
    member: MemberDetail;
    balanceByType: BalanceLine[];
    activePackages: ActivePackageOption[];
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
                        class="rounded-lg border border-border bg-muted/30 px-4 py-3"
                    >
                        <p class="text-sm text-muted-foreground">
                            {{ line.item_name }}
                        </p>
                        <p class="text-lg font-semibold tabular-nums">
                            {{ line.remaining }} ครั้ง
                        </p>
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
                        <p class="text-sm font-semibold tabular-nums">
                            {{ formatBaht(lot.price_paid) }}
                        </p>
                    </div>

                    <ul class="mt-3 flex flex-col gap-2 border-t border-border pt-3">
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
                            <span class="tabular-nums text-muted-foreground">
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
