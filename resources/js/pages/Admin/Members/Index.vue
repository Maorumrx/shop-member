<script setup lang="ts">
/**
 * Admin/Members/Index — member list + search + create/edit (architecture.md §3.3).
 *
 * Members are the "who do we sell to" surface. They're NEVER hard-deleted —
 * there is no delete control here; deactivate via the `is_active` checkbox in the
 * shared create/edit Dialog (mirrors Branches/Index). Search runs over name OR
 * phone via a debounced `router.get(?q=)` that preserves state + replaces history
 * so typing doesn't spam the back stack. Flash toasts fire globally.
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Search, UsersRound } from '@lucide/vue';
import { ref, watch } from 'vue';
import Pagination from '@/components/admin/Pagination.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import type { Paginator } from '@/types/catalog';
import type { MemberRow } from '@/types/members';

const props = defineProps<{
    members: Paginator<MemberRow>;
    filters: { q: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'สมาชิก', href: '/members' }],
    },
});

/**
 * Debounced search. We hold the box value locally and push it to the server on a
 * trailing 300ms timer; `preserveState` keeps focus/scroll, `replace` avoids
 * piling each keystroke onto the browser history.
 */
const search = ref(props.filters.q);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(
            '/members',
            { q: value },
            { preserveState: true, replace: true, preserveScroll: true },
        );
    }, 300);
});

/** The create/edit form. `editingId` null = create, set = edit. */
const dialogOpen = ref(false);
const editingId = ref<number | null>(null);

// No `email` here on purpose: the Index list projection has no email, so editing
// from this dialog must never submit a (blank) email and null an existing one.
// Email is created/edited from the member's Show page, which has the full record.
const form = useForm<{
    name: string;
    phone: string;
    is_active: boolean;
}>({
    name: '',
    phone: '',
    is_active: true,
});

function openCreate(): void {
    editingId.value = null;
    form.clearErrors();
    form.reset();
    dialogOpen.value = true;
}

function openEdit(member: MemberRow): void {
    editingId.value = member.id;
    form.clearErrors();
    form.name = member.name;
    form.phone = member.phone ?? '';
    form.is_active = member.is_active;
    dialogOpen.value = true;
}

function submit(): void {
    if (editingId.value === null) {
        form.post('/members', {
            preserveScroll: true,
            onSuccess: () => {
                dialogOpen.value = false;
                form.reset();
            },
        });

        return;
    }

    form.put(`/members/${editingId.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    });
}
</script>

<template>
    <Head title="สมาชิก" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <UsersRound class="size-5 text-muted-foreground" />
                <h1 class="text-xl font-semibold">สมาชิก</h1>
            </div>
            <Button @click="openCreate">
                <Plus />
                เพิ่มสมาชิก
            </Button>
        </div>

        <!-- Search (debounced, name OR phone) -->
        <div class="relative max-w-sm">
            <Search
                class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="search"
                type="search"
                placeholder="ค้นหาชื่อหรือเบอร์โทร"
                class="pl-9"
                aria-label="ค้นหาสมาชิก"
            />
        </div>

        <div class="overflow-x-auto rounded-xl border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">ชื่อ</th>
                        <th class="px-4 py-3 font-medium">เบอร์โทร</th>
                        <th class="px-4 py-3 font-medium">LINE</th>
                        <th class="px-4 py-3 font-medium">สถานะ</th>
                        <th class="px-4 py-3 text-right font-medium">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="member in props.members.data"
                        :key="member.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3 font-medium">{{ member.name }}</td>
                        <td class="px-4 py-3 tabular-nums">
                            <span v-if="member.phone">{{ member.phone }}</span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge
                                v-if="member.is_line_linked"
                                variant="default"
                            >
                                เชื่อม LINE แล้ว
                            </Badge>
                            <Badge v-else variant="secondary">
                                ยังไม่เชื่อม
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <Badge
                                :variant="
                                    member.is_active ? 'default' : 'secondary'
                                "
                            >
                                {{ member.is_active ? 'เปิดใช้งาน' : 'ปิด' }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="openEdit(member)"
                                >
                                    <Pencil />
                                    แก้ไข
                                </Button>
                                <Button as-child variant="outline" size="sm">
                                    <Link :href="`/members/${member.id}`">
                                        ดู
                                    </Link>
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="props.members.data.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            <template v-if="props.filters.q">
                                ไม่พบสมาชิกที่ตรงกับ “{{ props.filters.q }}”
                            </template>
                            <template v-else>
                                ยังไม่มีสมาชิก — กด “เพิ่มสมาชิก” เพื่อเริ่มต้น
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="props.members" />
    </div>

    <!-- Create / Edit dialog (shared form) -->
    <Dialog v-model:open="dialogOpen">
        <DialogContent>
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            editingId === null ? 'เพิ่มสมาชิก' : 'แก้ไขสมาชิก'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        สร้างบัญชีสมาชิกหน้าเคาน์เตอร์ การเชื่อม LINE
                        ทำภายหลังได้ ปิดใช้งานแทนการลบ
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label for="member-name">ชื่อ</Label>
                        <Input
                            id="member-name"
                            v-model="form.name"
                            autofocus
                            placeholder="เช่น สมหญิง ใจดี"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="member-phone">เบอร์โทร</Label>
                        <Input
                            id="member-phone"
                            v-model="form.phone"
                            type="tel"
                            inputmode="tel"
                            placeholder="เช่น 0812345678"
                        />
                        <InputError :message="form.errors.phone" />
                    </div>

                    <div class="flex items-center gap-2">
                        <Checkbox
                            id="member-active"
                            :model-value="form.is_active"
                            @update:model-value="
                                form.is_active = $event === true
                            "
                        />
                        <Label for="member-active">เปิดใช้งาน</Label>
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
</template>
