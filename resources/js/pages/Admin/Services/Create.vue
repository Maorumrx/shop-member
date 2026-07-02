<script setup lang="ts">
/**
 * Admin/Services/Create — new service. Thin wrapper over ServiceForm: provides
 * empty defaults (active) and POSTs to /services. The redirect + success toast
 * are handled by the controller.
 */
import { Head, useForm } from '@inertiajs/vue3';
import ServiceForm from '@/pages/Admin/Services/ServiceForm.vue';
import type { ServiceFormData } from '@/pages/Admin/Services/ServiceForm.vue';
import type { BranchOption } from '@/types/catalog';

const props = defineProps<{
    branches: BranchOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'บริการ', href: '/services' },
            { title: 'เพิ่มบริการ', href: '/services/create' },
        ],
    },
});

const form = useForm<ServiceFormData>({
    item_code: '',
    name: '',
    price: '',
    branch_id: null,
    is_active: true,
});

function submit(): void {
    form.post('/services');
}
</script>

<template>
    <Head title="เพิ่มบริการ" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="font-heading text-xl font-semibold">เพิ่มบริการ</h1>

        <ServiceForm
            v-model:form="form"
            :branches="props.branches"
            submit-label="สร้างบริการ"
            @submit="submit"
        />
    </div>
</template>
