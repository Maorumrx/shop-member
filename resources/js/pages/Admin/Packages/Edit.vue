<script setup lang="ts">
/**
 * Admin/Packages/Edit — edit an existing package. Prefills PackageForm from the
 * `package` prop and PUTs to /packages/{id}. Line `id`s are intentionally
 * dropped from the payload: the backend replaces the whole line set on update
 * (architecture.md §3.5). `price` / `valid_days` are stringified for the number
 * inputs (price is a decimal cast that arrives as a string anyway).
 */
import { Head, useForm } from '@inertiajs/vue3';
import PackageForm from '@/pages/Admin/Packages/PackageForm.vue';
import type { PackageFormData } from '@/pages/Admin/Packages/PackageForm.vue';
import type { BranchOption, PackageDetail } from '@/types/catalog';

const props = defineProps<{
    package: PackageDetail;
    branches: BranchOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'แพ็คเกจ', href: '/packages' },
            { title: 'แก้ไขแพ็คเกจ', href: '#' },
        ],
    },
});

const form = useForm<PackageFormData>({
    name: props.package.name,
    price: String(props.package.price ?? ''),
    valid_days:
        props.package.valid_days === null
            ? ''
            : String(props.package.valid_days),
    branch_id: props.package.branch_id,
    is_active: props.package.is_active,
    lines: props.package.lines.map((line) => ({
        item_code: line.item_code,
        item_name: line.item_name,
        item_type: line.item_type,
        qty: line.qty,
        redeem_group: line.redeem_group ?? '',
    })),
});

function submit(): void {
    form.put(`/packages/${props.package.id}`);
}
</script>

<template>
    <Head title="แก้ไขแพ็คเกจ" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">แก้ไขแพ็คเกจ</h1>

        <PackageForm
            v-model:form="form"
            :branches="props.branches"
            submit-label="บันทึกการแก้ไข"
            @submit="submit"
        />
    </div>
</template>
