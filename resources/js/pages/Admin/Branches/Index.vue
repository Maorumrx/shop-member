<script setup lang="ts">
/**
 * Admin/Branches/Index — branch list + create/edit/delete (architecture.md §3.1).
 *
 * Branches are reference/scoping data. They're toggled off via `is_active`
 * rather than deleted; deleting a branch that still has packages bound to it is
 * rejected at the DB (FK RESTRICT) and surfaced as a flash error by the
 * controller. Create + edit happen in a single Dialog (shared form), keeping the
 * table dense. Flash toasts fire globally — no per-page toast handling here.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { Building2, CalendarClock, Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import Pagination from '@/components/admin/Pagination.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
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
import type { BranchRow, Paginator } from '@/types/catalog';

const props = defineProps<{
    branches: Paginator<BranchRow>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'สาขา', href: '/branches' }],
    },
});

/** Toggle active without leaving the page. */
function toggle(branch: BranchRow): void {
    router.patch(
        `/branches/${branch.id}/toggle`,
        {},
        { preserveScroll: true, preserveState: true },
    );
}

/** The create/edit form. `editingId` null = create, set = edit. */
const dialogOpen = ref(false);
const editingId = ref<number | null>(null);

const form = useForm<{ name: string; is_active: boolean }>({
    name: '',
    is_active: true,
});

function openCreate(): void {
    editingId.value = null;
    form.clearErrors();
    form.reset();
    dialogOpen.value = true;
}

function openEdit(branch: BranchRow): void {
    editingId.value = branch.id;
    form.clearErrors();
    form.name = branch.name;
    form.is_active = branch.is_active;
    dialogOpen.value = true;
}

function submit(): void {
    if (editingId.value === null) {
        form.post('/branches', {
            preserveScroll: true,
            onSuccess: () => {
                dialogOpen.value = false;
                form.reset();
            },
        });

        return;
    }

    form.put(`/branches/${editingId.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    });
}

/** Delete confirmation. */
const deleteTarget = ref<BranchRow | null>(null);
const deleteForm = useForm({});

function confirmDelete(): void {
    if (!deleteTarget.value) {
        return;
    }

    deleteForm.delete(`/branches/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteTarget.value = null;
        },
    });
}

/**
 * Per-branch booking settings editor (Phase 7). `bookingBranch` = the branch
 * whose config the dialog is editing (null = closed). Pre-filled from the row's
 * `booking`, or sensible defaults when the branch has no settings row yet.
 * Submits H:i times; the backend appends ':00' before persisting.
 */
const bookingBranch = ref<BranchRow | null>(null);

const bookingForm = useForm<{
    is_bookable: boolean;
    slot_capacity: number;
    slot_length_minutes: number;
    open_time: string;
    close_time: string;
    max_advance_days: number;
}>({
    is_bookable: false,
    slot_capacity: 1,
    slot_length_minutes: 60,
    open_time: '10:00',
    close_time: '20:00',
    max_advance_days: 30,
});

function openBooking(branch: BranchRow): void {
    bookingBranch.value = branch;
    bookingForm.clearErrors();

    const b = branch.booking;
    bookingForm.is_bookable = b?.is_bookable ?? false;
    bookingForm.slot_capacity = b?.slot_capacity ?? 1;
    bookingForm.slot_length_minutes = b?.slot_length_minutes ?? 60;
    bookingForm.open_time = b?.open_time ?? '10:00';
    bookingForm.close_time = b?.close_time ?? '20:00';
    bookingForm.max_advance_days = b?.max_advance_days ?? 30;
}

function submitBooking(): void {
    if (!bookingBranch.value) {
        return;
    }

    bookingForm.put(
        `/branches/${bookingBranch.value.id}/booking-settings`,
        {
            preserveScroll: true,
            onSuccess: () => {
                bookingBranch.value = null;
            },
        },
    );
}
</script>

<template>
    <Head title="สาขา" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <Building2 class="size-5 text-muted-foreground" />
                <h1 class="font-heading text-xl font-semibold">สาขา</h1>
            </div>
            <Button @click="openCreate">
                <Plus />
                เพิ่มสาขา
            </Button>
        </div>

        <div class="overflow-hidden rounded-xl border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">ชื่อสาขา</th>
                        <th class="px-4 py-3 font-medium">สถานะ</th>
                        <th class="px-4 py-3 font-medium">การจอง</th>
                        <th class="px-4 py-3 text-right font-medium">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="branch in props.branches.data"
                        :key="branch.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3 font-medium">{{ branch.name }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <Switch
                                    :model-value="branch.is_active"
                                    :aria-label="
                                        branch.is_active
                                            ? 'ปิดใช้งาน'
                                            : 'เปิดใช้งาน'
                                    "
                                    @update:model-value="toggle(branch)"
                                />
                                <span
                                    class="text-xs"
                                    :class="
                                        branch.is_active
                                            ? 'text-foreground'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ branch.is_active ? 'เปิดใช้งาน' : 'ปิด' }}
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <Badge
                                v-if="branch.booking?.is_bookable"
                                variant="secondary"
                            >
                                เปิดจอง
                            </Badge>
                            <span v-else class="text-xs text-muted-foreground">
                                ปิดจอง
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="openBooking(branch)"
                                >
                                    <CalendarClock />
                                    ตั้งค่าการจอง
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="openEdit(branch)"
                                >
                                    <Pencil />
                                    แก้ไข
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="text-destructive hover:text-destructive"
                                    @click="deleteTarget = branch"
                                >
                                    <Trash2 />
                                    ลบ
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="props.branches.data.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            ยังไม่มีสาขา — กด “เพิ่มสาขา” เพื่อเริ่มต้น
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="props.branches" />
    </div>

    <!-- Create / Edit dialog (shared form) -->
    <Dialog v-model:open="dialogOpen">
        <DialogContent>
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>
                        {{ editingId === null ? 'เพิ่มสาขา' : 'แก้ไขสาขา' }}
                    </DialogTitle>
                    <DialogDescription>
                        ชื่อสาขาใช้ระบุขอบเขตของแพ็คเกจ
                        ปิดใช้งานเพื่อซ่อนจากการเลือกโดยไม่ต้องลบ
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label for="branch-name">ชื่อสาขา</Label>
                        <Input
                            id="branch-name"
                            v-model="form.name"
                            autofocus
                            placeholder="เช่น สาขาสยาม"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="flex items-center gap-2">
                        <Switch
                            id="branch-active"
                            :model-value="form.is_active"
                            @update:model-value="
                                form.is_active = $event === true
                            "
                        />
                        <Label for="branch-active">เปิดใช้งาน</Label>
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
                <DialogTitle>ลบสาขา</DialogTitle>
                <DialogDescription>
                    ต้องการลบสาขา “{{ deleteTarget?.name }}” ใช่หรือไม่?
                    หากมีแพ็คเกจผูกกับสาขานี้จะไม่สามารถลบได้
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

    <!-- Booking settings (Phase 7) — per-branch slot config -->
    <Dialog
        :open="bookingBranch !== null"
        @update:open="(open) => !open && (bookingBranch = null)"
    >
        <DialogContent>
            <form @submit.prevent="submitBooking">
                <DialogHeader>
                    <DialogTitle>
                        ตั้งค่าการจอง — {{ bookingBranch?.name }}
                    </DialogTitle>
                    <DialogDescription>
                        กำหนดช่วงเวลาให้บริการ ความยาวคิว
                        จำนวนที่รับได้ต่อคิว และระยะเวลาที่จองล่วงหน้าได้
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <div class="flex items-center gap-2">
                        <Switch
                            id="booking-bookable"
                            :model-value="bookingForm.is_bookable"
                            @update:model-value="
                                bookingForm.is_bookable = $event === true
                            "
                        />
                        <Label for="booking-bookable">เปิดให้จองคิว</Label>
                    </div>
                    <InputError :message="bookingForm.errors.is_bookable" />

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="booking-open">เวลาเปิด</Label>
                            <Input
                                id="booking-open"
                                v-model="bookingForm.open_time"
                                type="time"
                            />
                            <InputError :message="bookingForm.errors.open_time" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="booking-close">เวลาปิด</Label>
                            <Input
                                id="booking-close"
                                v-model="bookingForm.close_time"
                                type="time"
                            />
                            <InputError
                                :message="bookingForm.errors.close_time"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="booking-length">
                                ความยาวคิว (นาที)
                            </Label>
                            <Input
                                id="booking-length"
                                v-model="bookingForm.slot_length_minutes"
                                type="number"
                                min="1"
                                max="480"
                            />
                            <InputError
                                :message="bookingForm.errors.slot_length_minutes"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="booking-capacity">
                                จำนวนที่รับได้ต่อคิว
                            </Label>
                            <Input
                                id="booking-capacity"
                                v-model="bookingForm.slot_capacity"
                                type="number"
                                min="1"
                                max="100"
                            />
                            <InputError
                                :message="bookingForm.errors.slot_capacity"
                            />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="booking-advance">
                            จองล่วงหน้าได้กี่วัน
                        </Label>
                        <Input
                            id="booking-advance"
                            v-model="bookingForm.max_advance_days"
                            type="number"
                            min="0"
                            max="365"
                        />
                        <InputError
                            :message="bookingForm.errors.max_advance_days"
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="bookingBranch = null"
                    >
                        ยกเลิก
                    </Button>
                    <Button type="submit" :disabled="bookingForm.processing">
                        บันทึก
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
