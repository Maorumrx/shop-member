<script setup lang="ts">
/**
 * Admin/Services/ServiceForm — shared create/edit form for the baht price list.
 *
 * Owns the service fields: item_code (globally unique business code, shared with
 * bookings), name, price (บาท), an optional branch scope (null = ทุกสาขา), and an
 * active toggle. GOLDEN RULE (server-enforced): editing a price never rewrites
 * past debits — this form only mutates the live catalog definition.
 *
 * The parent owns a configured Inertia `useForm` instance and passes it via
 * `v-model:form`; ServiceForm mutates that model and emits `submit`, so Create /
 * Edit differ only in defaults + endpoint (DRY, mirroring the old PackageForm).
 */
import { Link } from '@inertiajs/vue3';
import type { InertiaForm } from '@inertiajs/vue3';
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
import type { BranchOption } from '@/types/catalog';

export type ServiceFormData = {
    item_code: string;
    name: string;
    price: string;
    branch_id: number | null;
    is_active: boolean;
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
const form = defineModel<InertiaForm<ServiceFormData>>('form', {
    required: true,
});

/** Sentinel for the "ทุกสาขา" (null branch) option — Select needs a string value. */
const ALL_BRANCHES = '__all__';

function onBranchChange(value: string): void {
    form.value.branch_id = value === ALL_BRANCHES ? null : Number(value);
}
</script>

<template>
    <form class="flex flex-col gap-6" @submit.prevent="emit('submit')">
        <div class="rounded-xl border border-border p-6">
            <h2 class="mb-4 font-heading text-base font-semibold">
                รายละเอียดบริการ
            </h2>

            <div class="grid gap-6 md:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="item_code">รหัสบริการ</Label>
                    <Input
                        id="item_code"
                        v-model="form.item_code"
                        placeholder="เช่น MSG60"
                    />
                    <p class="text-xs text-muted-foreground">
                        รหัสไม่ซ้ำกัน ใช้ร่วมกับการจองคิว
                    </p>
                    <InputError :message="form.errors.item_code" />
                </div>

                <div class="grid gap-2">
                    <Label for="name">ชื่อบริการ</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        placeholder="เช่น นวดไทย 60 นาที"
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
                    <p class="text-xs text-muted-foreground">
                        หักจากเครดิตของสมาชิกเมื่อใช้บริการ
                    </p>
                    <InputError :message="form.errors.price" />
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
                <Label for="is_active">เปิดใช้งาน</Label>
            </div>
            <InputError class="mt-1" :message="form.errors.is_active" />
        </div>

        <div class="flex items-center justify-end gap-3">
            <Button as-child type="button" variant="outline">
                <Link href="/services">ยกเลิก</Link>
            </Button>
            <Button type="submit" :disabled="form.processing">
                {{ submitLabel }}
            </Button>
        </div>
    </form>
</template>
