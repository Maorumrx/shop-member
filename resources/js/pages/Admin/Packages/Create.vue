<script setup lang="ts">
/**
 * Admin/Packages/Create — new package. Thin wrapper over PackageForm: provides
 * empty defaults (one blank line) and POSTs to /packages. The redirect +
 * success toast are handled by the controller.
 */
import { Head, useForm } from '@inertiajs/vue3';
import PackageForm from '@/pages/Admin/Packages/PackageForm.vue';
import type { PackageFormData } from '@/pages/Admin/Packages/PackageForm.vue';
import type { BranchOption } from '@/types/catalog';

const props = defineProps<{
    branches: BranchOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'แพ็คเกจ', href: '/packages' },
            { title: 'เพิ่มแพ็คเกจ', href: '/packages/create' },
        ],
    },
});

const form = useForm<PackageFormData>({
    name: '',
    price: '',
    valid_days: '',
    branch_id: null,
    is_active: true,
    lines: [
        {
            item_code: '',
            item_name: '',
            item_type: 'service',
            qty: 1,
            redeem_group: '',
        },
    ],
});

function submit(): void {
    form.post('/packages');
}
</script>

<template>
    <Head title="เพิ่มแพ็คเกจ" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="font-heading text-xl font-semibold">เพิ่มแพ็คเกจ</h1>

        <PackageForm
            v-model:form="form"
            :branches="props.branches"
            submit-label="สร้างแพ็คเกจ"
            @submit="submit"
        />
    </div>
</template>
