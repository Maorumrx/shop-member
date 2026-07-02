<script setup lang="ts">
/**
 * Admin/Services/Edit — edit an existing service. Prefills ServiceForm from the
 * `service` prop and PUTs to /services/{id}. `price` is stringified for the
 * number input (it arrives as a decimal-cast string anyway). Editing a price
 * never touches past debits — enforced server-side.
 */
import { Head, useForm } from '@inertiajs/vue3';
import ServiceForm from '@/pages/Admin/Services/ServiceForm.vue';
import type { ServiceFormData } from '@/pages/Admin/Services/ServiceForm.vue';
import type { BranchOption, ServiceDetail } from '@/types/catalog';

const props = defineProps<{
    service: ServiceDetail;
    branches: BranchOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'บริการ', href: '/services' },
            { title: 'แก้ไขบริการ', href: '#' },
        ],
    },
});

const form = useForm<ServiceFormData>({
    item_code: props.service.item_code,
    name: props.service.name,
    price: String(props.service.price ?? ''),
    branch_id: props.service.branch_id,
    is_active: props.service.is_active,
});

function submit(): void {
    form.put(`/services/${props.service.id}`);
}
</script>

<template>
    <Head title="แก้ไขบริการ" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="font-heading text-xl font-semibold">แก้ไขบริการ</h1>

        <ServiceForm
            v-model:form="form"
            :branches="props.branches"
            submit-label="บันทึกการแก้ไข"
            @submit="submit"
        />
    </div>
</template>
