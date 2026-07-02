<script setup lang="ts">
/**
 * Admin/Services/Index — the baht price-list catalog (credit-wallet reframe of
 * the dropped package catalog). Owner-only (route `role:owner`).
 *
 * Table of services with an inline active toggle (PATCH /services/{id}/toggle),
 * edit link, and delete (confirm dialog). Editing/deleting the catalog never
 * touches past debits — enforced server-side. Flash toasts fire globally.
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Scissors, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import Pagination from '@/components/admin/Pagination.vue';
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
import { Switch } from '@/components/ui/switch';
import { formatBaht } from '@/lib/money';
import type { Paginator, ServiceRow } from '@/types/catalog';

const props = defineProps<{
    services: Paginator<ServiceRow>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'บริการ', href: '/services' }],
    },
});

/** Toggle active without leaving the page. */
function toggle(service: ServiceRow): void {
    router.patch(
        `/services/${service.id}/toggle`,
        {},
        { preserveScroll: true, preserveState: true },
    );
}

/** Delete confirmation. */
const deleteTarget = ref<ServiceRow | null>(null);
const deleteForm = useForm({});

function confirmDelete(): void {
    if (!deleteTarget.value) {
        return;
    }

    deleteForm.delete(`/services/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteTarget.value = null;
        },
    });
}
</script>

<template>
    <Head title="บริการ" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <Scissors class="size-5 text-muted-foreground" />
                <h1 class="font-heading text-xl font-semibold">บริการ</h1>
            </div>

            <Button as-child>
                <Link href="/services/create">
                    <Plus />
                    เพิ่มบริการ
                </Link>
            </Button>
        </div>

        <div class="overflow-x-auto rounded-xl border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">รหัส</th>
                        <th class="px-4 py-3 font-medium">ชื่อบริการ</th>
                        <th class="px-4 py-3 text-right font-medium">ราคา</th>
                        <th class="px-4 py-3 font-medium">สาขา</th>
                        <th class="px-4 py-3 font-medium">เปิดใช้งาน</th>
                        <th class="px-4 py-3 text-right font-medium">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="service in props.services.data"
                        :key="service.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3 font-mono text-xs text-muted-foreground">
                            {{ service.item_code }}
                        </td>
                        <td class="px-4 py-3 font-medium">{{ service.name }}</td>
                        <td
                            class="px-4 py-3 text-right font-heading font-semibold tabular-nums"
                        >
                            {{ formatBaht(service.price) }}
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="service.branch">
                                {{ service.branch.name }}
                            </span>
                            <Badge v-else variant="secondary">ทุกสาขา</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <Switch
                                    :model-value="service.is_active"
                                    :aria-label="
                                        service.is_active
                                            ? 'ปิดใช้งาน'
                                            : 'เปิดใช้งาน'
                                    "
                                    @update:model-value="toggle(service)"
                                />
                                <span
                                    class="text-xs"
                                    :class="
                                        service.is_active
                                            ? 'text-foreground'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ service.is_active ? 'เปิด' : 'ปิด' }}
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <Button as-child variant="outline" size="sm">
                                    <Link
                                        :href="`/services/${service.id}/edit`"
                                    >
                                        <Pencil />
                                        แก้ไข
                                    </Link>
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="text-destructive hover:text-destructive"
                                    @click="deleteTarget = service"
                                >
                                    <Trash2 />
                                    ลบ
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="props.services.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            ยังไม่มีบริการ — กด “เพิ่มบริการ” เพื่อเริ่มต้น
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="props.services" />
    </div>

    <!-- Delete confirmation -->
    <Dialog
        :open="deleteTarget !== null"
        @update:open="(open) => !open && (deleteTarget = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>ลบบริการ</DialogTitle>
                <DialogDescription>
                    ต้องการลบบริการ “{{ deleteTarget?.name }}” ใช่หรือไม่?
                    การลบจะไม่กระทบประวัติการใช้บริการที่ผ่านมา
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
