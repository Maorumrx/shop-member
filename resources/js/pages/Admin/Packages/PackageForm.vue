<script setup lang="ts">
/**
 * Admin/Packages/PackageForm — shared create/edit form (architecture.md §3.4–3.5).
 *
 * Owns header fields (name, price, valid_days, branch scope, active) plus a lines
 * repeater (≥1 row enforced in the UI). Submits the full `{..., lines: [...] }`
 * payload; on Edit the backend REPLACES the whole line set, so line `id`s are
 * omitted from the payload. `is_active` is ALWAYS sent as a boolean (the backend
 * treats an omitted value as false).
 *
 * The parent owns a configured Inertia `useForm` instance and passes it via
 * `v-model:form`; PackageForm mutates that model (lines repeater, branch scope)
 * and emits `submit` so Create/Edit differ only in defaults + endpoint (DRY).
 */
import { Link } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import type { InertiaForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { BranchOption, PackageLineType } from '@/types/catalog';

/** A blank line for the repeater. */
export type PackageFormLine = {
    item_code: string;
    item_name: string;
    item_type: PackageLineType;
    qty: number;
    redeem_group: string;
};

export type PackageFormData = {
    name: string;
    price: string;
    valid_days: string;
    branch_id: number | null;
    is_active: boolean;
    lines: PackageFormLine[];
};

defineProps<{
    branches: BranchOption[];
    submitLabel: string;
}>();

const emit = defineEmits<{
    (e: 'submit'): void;
}>();

/**
 * The Inertia form, owned by the parent and bound via `v-model:form`. Mutating
 * `form.value.*` mutates the model (allowed), not a raw prop.
 */
const form = defineModel<InertiaForm<PackageFormData>>('form', {
    required: true,
});

/** Sentinel for the "ทุกสาขา" (null branch) option — Select needs a string value. */
const ALL_BRANCHES = '__all__';

/** Top-level `lines` error (e.g. "at least one line required"), if any. */
const linesError = computed(
    () => form.value.errors.lines as string | undefined,
);

function makeLine(): PackageFormLine {
    return {
        item_code: '',
        item_name: '',
        item_type: 'service',
        qty: 1,
        redeem_group: '',
    };
}

function addLine(): void {
    form.value.lines.push(makeLine());
}

function removeLine(index: number): void {
    // Enforce ≥1 line in the UI; never drop the last row.
    if (form.value.lines.length > 1) {
        form.value.lines.splice(index, 1);
    }
}

function onBranchChange(value: string): void {
    form.value.branch_id = value === ALL_BRANCHES ? null : Number(value);
}

function lineError(
    index: number,
    field: keyof PackageFormLine,
): string | undefined {
    return form.value.errors[
        `lines.${index}.${field}` as keyof typeof form.value.errors
    ] as string | undefined;
}
</script>

<template>
    <form class="flex flex-col gap-6" @submit.prevent="emit('submit')">
        <!-- Header fields -->
        <div class="rounded-xl border border-border p-6">
            <h2 class="mb-4 text-base font-semibold">รายละเอียดแพ็คเกจ</h2>

            <div class="grid gap-6 md:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="name">ชื่อแพ็คเกจ</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        placeholder="เช่น คอร์สนวด 10 ครั้ง"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="price">ราคา (บาท)</Label>
                    <Input
                        id="price"
                        v-model="form.price"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder="0.00"
                    />
                    <InputError :message="form.errors.price" />
                </div>

                <div class="grid gap-2">
                    <Label for="valid_days">อายุการใช้งาน (วัน)</Label>
                    <Input
                        id="valid_days"
                        v-model="form.valid_days"
                        type="number"
                        min="1"
                        placeholder="เว้นว่าง = ไม่หมดอายุ"
                    />
                    <p class="text-xs text-muted-foreground">
                        เว้นว่างหมายถึงแพ็คเกจไม่มีวันหมดอายุ
                    </p>
                    <InputError :message="form.errors.valid_days" />
                </div>

                <div class="grid gap-2">
                    <Label for="branch_id">สาขา</Label>
                    <Select
                        :model-value="
                            form.branch_id === null
                                ? ALL_BRANCHES
                                : String(form.branch_id)
                        "
                        @update:model-value="onBranchChange($event as string)"
                    >
                        <SelectTrigger id="branch_id" class="w-full">
                            <SelectValue placeholder="เลือกสาขา" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL_BRANCHES">
                                ทุกสาขา
                            </SelectItem>
                            <SelectItem
                                v-for="branch in branches"
                                :key="branch.id"
                                :value="String(branch.id)"
                            >
                                {{ branch.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.branch_id" />
                </div>
            </div>

            <div class="mt-6 flex items-center gap-2">
                <Checkbox
                    id="is_active"
                    :model-value="form.is_active"
                    @update:model-value="form.is_active = $event === true"
                />
                <Label for="is_active">เปิดขาย</Label>
            </div>
            <InputError class="mt-1" :message="form.errors.is_active" />
        </div>

        <!-- Lines repeater -->
        <div class="rounded-xl border border-border p-6">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-semibold">รายการในแพ็คเกจ</h2>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addLine"
                >
                    <Plus />
                    เพิ่มบรรทัด
                </Button>
            </div>

            <!-- Top-level lines error (e.g. "at least one line required"). -->
            <InputError class="mb-4" :message="linesError" />

            <div class="flex flex-col gap-4">
                <div
                    v-for="(line, index) in form.lines"
                    :key="index"
                    class="rounded-lg border border-border bg-muted/30 p-4"
                >
                    <div class="flex items-start justify-between gap-4">
                        <span class="text-sm font-medium text-muted-foreground">
                            บรรทัดที่ {{ index + 1 }}
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="text-destructive hover:text-destructive"
                            :disabled="form.lines.length <= 1"
                            :aria-label="`ลบบรรทัดที่ ${index + 1}`"
                            @click="removeLine(index)"
                        >
                            <Trash2 />
                        </Button>
                    </div>

                    <div class="mt-3 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        <div class="grid gap-2">
                            <Label :for="`line-${index}-code`"
                                >รหัสรายการ</Label
                            >
                            <Input
                                :id="`line-${index}-code`"
                                v-model="line.item_code"
                                placeholder="เช่น MSG60"
                            />
                            <InputError
                                :message="lineError(index, 'item_code')"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label :for="`line-${index}-name`"
                                >ชื่อรายการ</Label
                            >
                            <Input
                                :id="`line-${index}-name`"
                                v-model="line.item_name"
                                placeholder="เช่น นวดไทย 60 นาที"
                            />
                            <InputError
                                :message="lineError(index, 'item_name')"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label :for="`line-${index}-type`">ประเภท</Label>
                            <Select
                                :model-value="line.item_type"
                                @update:model-value="
                                    line.item_type = $event as PackageLineType
                                "
                            >
                                <SelectTrigger
                                    :id="`line-${index}-type`"
                                    class="w-full"
                                >
                                    <SelectValue placeholder="เลือกประเภท" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="service">
                                        บริการ (service)
                                    </SelectItem>
                                    <SelectItem value="addon">
                                        เสริม (addon)
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError
                                :message="lineError(index, 'item_type')"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label :for="`line-${index}-qty`">จำนวน</Label>
                            <Input
                                :id="`line-${index}-qty`"
                                v-model.number="line.qty"
                                type="number"
                                min="1"
                            />
                            <InputError :message="lineError(index, 'qty')" />
                        </div>

                        <div class="grid gap-2 lg:col-span-2">
                            <Label :for="`line-${index}-group`">
                                กลุ่มการตัดสิทธิ์
                            </Label>
                            <Input
                                :id="`line-${index}-group`"
                                v-model="line.redeem_group"
                                placeholder="เว้นว่าง = ตัดอิสระ"
                            />
                            <p class="text-xs text-muted-foreground">
                                เว้นว่าง = ตัดอิสระ; ใส่ค่าเดียวกันหลายบรรทัด =
                                ตัดคู่กัน
                            </p>
                            <InputError
                                :message="lineError(index, 'redeem_group')"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-end gap-3">
            <Button as-child type="button" variant="outline">
                <Link href="/packages">ยกเลิก</Link>
            </Button>
            <Button type="submit" :disabled="form.processing">
                {{ submitLabel }}
            </Button>
        </div>
    </form>
</template>
