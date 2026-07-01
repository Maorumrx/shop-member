<script setup lang="ts">
/**
 * Admin/Packages/Index — package catalog list (architecture.md §3.4).
 *
 * Table of packages with an inline active toggle (PATCH /packages/{id}/toggle),
 * edit link, and delete (confirm dialog). An optional branch filter narrows the
 * list via a GET query param the backend reads. Editing the catalog never
 * touches sold lots — that's enforced server-side. Flash toasts fire globally.
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Package, Pencil, Plus, Trash2 } from '@lucide/vue';
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
import type { Paginator, PackageRow } from '@/types/catalog';

const props = defineProps<{
    packages: Paginator<PackageRow>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'แพ็คเกจ', href: '/packages' }],
    },
});

// NOTE: the backend `index` provides an active-branch list as an OPTIONAL
// client-side filter hook, but does not yet read a branch_id query param. To
// avoid shipping a control that silently does nothing, the filter UI is omitted
// here; wire it in once PackageController@index reads the param.

/** Toggle active without leaving the page. */
function toggle(pkg: PackageRow): void {
    router.patch(
        `/packages/${pkg.id}/toggle`,
        {},
        { preserveScroll: true, preserveState: true },
    );
}

/** Delete confirmation. */
const deleteTarget = ref<PackageRow | null>(null);
const deleteForm = useForm({});

function confirmDelete(): void {
    if (!deleteTarget.value) {
        return;
    }

    deleteForm.delete(`/packages/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteTarget.value = null;
        },
    });
}
</script>

<template>
    <Head title="แพ็คเกจ" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <Package class="size-5 text-muted-foreground" />
                <h1 class="font-heading text-xl font-semibold">แพ็คเกจ</h1>
            </div>

            <Button as-child>
                <Link href="/packages/create">
                    <Plus />
                    เพิ่มแพ็คเกจ
                </Link>
            </Button>
        </div>

        <div class="overflow-x-auto rounded-xl border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">ชื่อแพ็คเกจ</th>
                        <th class="px-4 py-3 text-right font-medium">ราคา</th>
                        <th class="px-4 py-3 font-medium">สาขา</th>
                        <th class="px-4 py-3 font-medium">อายุการใช้งาน</th>
                        <th class="px-4 py-3 text-center font-medium">
                            จำนวนรายการ
                        </th>
                        <th class="px-4 py-3 font-medium">เปิดขาย</th>
                        <th class="px-4 py-3 text-right font-medium">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="pkg in props.packages.data"
                        :key="pkg.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3 font-medium">{{ pkg.name }}</td>
                        <td
                            class="px-4 py-3 text-right font-heading font-semibold tabular-nums"
                        >
                            {{ formatBaht(pkg.price) }}
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="pkg.branch">{{ pkg.branch.name }}</span>
                            <Badge v-else variant="secondary">ทุกสาขา</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="pkg.valid_days != null">
                                {{ pkg.valid_days }} วัน
                            </span>
                            <span v-else class="text-muted-foreground">
                                ไม่หมดอายุ
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center tabular-nums">
                            {{ pkg.lines_count }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <Switch
                                    :model-value="pkg.is_active"
                                    :aria-label="
                                        pkg.is_active ? 'ปิดขาย' : 'เปิดขาย'
                                    "
                                    @update:model-value="toggle(pkg)"
                                />
                                <span
                                    class="text-xs"
                                    :class="
                                        pkg.is_active
                                            ? 'text-foreground'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ pkg.is_active ? 'เปิดขาย' : 'ปิด' }}
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <Button as-child variant="outline" size="sm">
                                    <Link :href="`/packages/${pkg.id}/edit`">
                                        <Pencil />
                                        แก้ไข
                                    </Link>
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="text-destructive hover:text-destructive"
                                    @click="deleteTarget = pkg"
                                >
                                    <Trash2 />
                                    ลบ
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="props.packages.data.length === 0">
                        <td
                            colspan="7"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            ยังไม่มีแพ็คเกจ — กด “เพิ่มแพ็คเกจ” เพื่อเริ่มต้น
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="props.packages" />
    </div>

    <!-- Delete confirmation -->
    <Dialog
        :open="deleteTarget !== null"
        @update:open="(open) => !open && (deleteTarget = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>ลบแพ็คเกจ</DialogTitle>
                <DialogDescription>
                    ต้องการลบแพ็คเกจ “{{ deleteTarget?.name }}” ใช่หรือไม่?
                    การลบจะไม่กระทบสิทธิ์ที่ขายไปแล้ว
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
