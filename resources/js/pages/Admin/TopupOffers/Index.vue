<script setup lang="ts">
/**
 * Admin/TopupOffers/Index — the sell-screen presets ("pay `amount` → get
 * `amount + bonus` spendable"). Owner-only (route `role:owner`).
 *
 * Presets are short quick-pick config managed INLINE on this page (no dedicated
 * create/edit routes, mirroring Branches): a shared create/edit Dialog, an inline
 * active toggle (PATCH /topup-offers/{id}/toggle), and a delete confirm. Editing a
 * preset never touches already-sold credit lots — enforced server-side. Flash
 * toasts fire globally.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Wallet } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
import { Switch } from '@/components/ui/switch';
import { formatBaht } from '@/lib/money';
import type { TopupOfferRow } from '@/types/catalog';

const props = defineProps<{
    offers: TopupOfferRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'แพ็กเกจเติมเครดิต', href: '/topup-offers' }],
    },
});

/** Spendable total a preset grants (amount + bonus), for the table + preview. */
function totalSpendable(offer: {
    amount: string | number;
    bonus: string | number;
}): number {
    return Number(offer.amount ?? 0) + Number(offer.bonus ?? 0);
}

/** Toggle active without leaving the page. */
function toggle(offer: TopupOfferRow): void {
    router.patch(
        `/topup-offers/${offer.id}/toggle`,
        {},
        { preserveScroll: true, preserveState: true },
    );
}

/** The create/edit form. `editingId` null = create, set = edit. */
const dialogOpen = ref(false);
const editingId = ref<number | null>(null);

const form = useForm<{
    name: string;
    amount: string;
    bonus: string;
    sort_order: number;
    is_active: boolean;
}>({
    name: '',
    amount: '',
    bonus: '',
    sort_order: 0,
    is_active: true,
});

/** Live spendable preview inside the dialog. */
const formTotal = computed(() => totalSpendable(form));

function openCreate(): void {
    editingId.value = null;
    form.clearErrors();
    form.reset();
    dialogOpen.value = true;
}

function openEdit(offer: TopupOfferRow): void {
    editingId.value = offer.id;
    form.clearErrors();
    form.name = offer.name;
    form.amount = String(offer.amount ?? '');
    form.bonus = String(offer.bonus ?? '');
    form.sort_order = offer.sort_order;
    form.is_active = offer.is_active;
    dialogOpen.value = true;
}

function submit(): void {
    if (editingId.value === null) {
        form.post('/topup-offers', {
            preserveScroll: true,
            onSuccess: () => {
                dialogOpen.value = false;
                form.reset();
            },
        });

        return;
    }

    form.put(`/topup-offers/${editingId.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    });
}

/** Delete confirmation. */
const deleteTarget = ref<TopupOfferRow | null>(null);
const deleteForm = useForm({});

function confirmDelete(): void {
    if (!deleteTarget.value) {
        return;
    }

    deleteForm.delete(`/topup-offers/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteTarget.value = null;
        },
    });
}
</script>

<template>
    <Head title="แพ็กเกจเติมเครดิต" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <Wallet class="size-5 text-muted-foreground" />
                <h1 class="font-heading text-xl font-semibold">
                    แพ็กเกจเติมเครดิต
                </h1>
            </div>
            <Button @click="openCreate">
                <Plus />
                เพิ่มแพ็กเกจ
            </Button>
        </div>

        <div class="overflow-x-auto rounded-xl border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">ชื่อ</th>
                        <th class="px-4 py-3 text-right font-medium">ยอดจ่าย</th>
                        <th class="px-4 py-3 text-right font-medium">โบนัส</th>
                        <th class="px-4 py-3 text-right font-medium">
                            เครดิตที่ได้
                        </th>
                        <th class="px-4 py-3 font-medium">เปิดใช้งาน</th>
                        <th class="px-4 py-3 text-right font-medium">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="offer in props.offers"
                        :key="offer.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3 font-medium">{{ offer.name }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatBaht(offer.amount) }}
                        </td>
                        <td
                            class="px-4 py-3 text-right tabular-nums text-[var(--color-success)]"
                        >
                            {{
                                Number(offer.bonus) > 0
                                    ? `+${formatBaht(offer.bonus)}`
                                    : '—'
                            }}
                        </td>
                        <td
                            class="px-4 py-3 text-right font-heading font-semibold tabular-nums"
                        >
                            {{ formatBaht(totalSpendable(offer)) }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <Switch
                                    :model-value="offer.is_active"
                                    :aria-label="
                                        offer.is_active
                                            ? 'ปิดใช้งาน'
                                            : 'เปิดใช้งาน'
                                    "
                                    @update:model-value="toggle(offer)"
                                />
                                <span
                                    class="text-xs"
                                    :class="
                                        offer.is_active
                                            ? 'text-foreground'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ offer.is_active ? 'เปิด' : 'ปิด' }}
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="openEdit(offer)"
                                >
                                    <Pencil />
                                    แก้ไข
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="text-destructive hover:text-destructive"
                                    @click="deleteTarget = offer"
                                >
                                    <Trash2 />
                                    ลบ
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="props.offers.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            ยังไม่มีแพ็กเกจเติมเครดิต — กด “เพิ่มแพ็กเกจ”
                            เพื่อเริ่มต้น
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create / Edit dialog (shared form) -->
    <Dialog v-model:open="dialogOpen">
        <DialogContent>
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            editingId === null
                                ? 'เพิ่มแพ็กเกจเติมเครดิต'
                                : 'แก้ไขแพ็กเกจเติมเครดิต'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        ตั้งยอดที่ลูกค้าจ่ายและโบนัสที่แถมให้
                        ลูกค้าจะได้เครดิตใช้จ่ายเท่ากับ ยอดจ่าย + โบนัส
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label for="offer-name">ชื่อแพ็กเกจ</Label>
                        <Input
                            id="offer-name"
                            v-model="form.name"
                            autofocus
                            placeholder="เช่น เติม 10,000 แถม 1,000"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="offer-amount">ยอดจ่าย (บาท)</Label>
                            <Input
                                id="offer-amount"
                                v-model="form.amount"
                                type="number"
                                step="0.01"
                                min="0"
                                placeholder="0.00"
                            />
                            <InputError :message="form.errors.amount" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="offer-bonus">โบนัส (บาท)</Label>
                            <Input
                                id="offer-bonus"
                                v-model="form.bonus"
                                type="number"
                                step="0.01"
                                min="0"
                                placeholder="0.00"
                            />
                            <InputError :message="form.errors.bonus" />
                        </div>
                    </div>

                    <div
                        class="flex items-center justify-between rounded-lg border border-border bg-muted/30 px-4 py-3"
                    >
                        <span class="text-sm text-muted-foreground">
                            เครดิตที่ลูกค้าจะได้
                        </span>
                        <span
                            class="font-heading text-lg font-bold tabular-nums"
                        >
                            {{ formatBaht(formTotal) }}
                        </span>
                    </div>

                    <div class="grid gap-2">
                        <Label for="offer-sort">ลำดับการแสดง</Label>
                        <Input
                            id="offer-sort"
                            v-model.number="form.sort_order"
                            type="number"
                            min="0"
                        />
                        <p class="text-xs text-muted-foreground">
                            ค่าน้อยแสดงก่อนบนหน้าจอขายเครดิต
                        </p>
                        <InputError :message="form.errors.sort_order" />
                    </div>

                    <div class="flex items-center gap-2">
                        <Switch
                            id="offer-active"
                            :model-value="form.is_active"
                            @update:model-value="
                                form.is_active = $event === true
                            "
                        />
                        <Label for="offer-active">เปิดใช้งาน</Label>
                    </div>
                    <InputError :message="form.errors.is_active" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="dialogOpen = false"
                    >
                        ยกเลิก
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        บันทึก
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Delete confirmation -->
    <Dialog
        :open="deleteTarget !== null"
        @update:open="(open) => !open && (deleteTarget = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>ลบแพ็กเกจเติมเครดิต</DialogTitle>
                <DialogDescription>
                    ต้องการลบ “{{ deleteTarget?.name }}” ใช่หรือไม่?
                    การลบจะไม่กระทบเครดิตที่ขายไปแล้ว
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    @click="deleteTarget = null"
                >
                    ยกเลิก
                </Button>
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="deleteForm.processing"
                    @click="confirmDelete"
                >
                    ลบ
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
