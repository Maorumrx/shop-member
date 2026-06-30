<script setup lang="ts" generic="T">
/**
 * Admin table pagination — thin wrapper over a Laravel length-aware paginator.
 *
 * Renders a "showing X–Y of Z" summary plus prev/next buttons that navigate via
 * Inertia `Link` (preserving scroll). Hidden entirely when there's a single page.
 * Links use the paginator's own `prev_page_url` / `next_page_url`, so query
 * params (e.g. a branch filter) are preserved by the backend.
 */
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import type { Paginator } from '@/types/catalog';

const props = defineProps<{
    paginator: Paginator<T>;
}>();
</script>

<template>
    <div
        v-if="props.paginator.last_page > 1"
        class="flex items-center justify-between gap-4 px-1 pt-2"
    >
        <p class="text-sm text-muted-foreground">
            แสดง {{ props.paginator.from ?? 0 }}–{{
                props.paginator.to ?? 0
            }}
            จาก {{ props.paginator.total }} รายการ
        </p>

        <div class="flex items-center gap-2">
            <Button
                v-if="props.paginator.prev_page_url"
                as-child
                variant="outline"
                size="sm"
            >
                <Link
                    :href="props.paginator.prev_page_url"
                    preserve-scroll
                    preserve-state
                >
                    <ChevronLeft />
                    ก่อนหน้า
                </Link>
            </Button>
            <Button v-else variant="outline" size="sm" disabled>
                <ChevronLeft />
                ก่อนหน้า
            </Button>

            <span class="text-sm text-muted-foreground">
                หน้า {{ props.paginator.current_page }} /
                {{ props.paginator.last_page }}
            </span>

            <Button
                v-if="props.paginator.next_page_url"
                as-child
                variant="outline"
                size="sm"
            >
                <Link
                    :href="props.paginator.next_page_url"
                    preserve-scroll
                    preserve-state
                >
                    ถัดไป
                    <ChevronRight />
                </Link>
            </Button>
            <Button v-else variant="outline" size="sm" disabled>
                ถัดไป
                <ChevronRight />
            </Button>
        </div>
    </div>
</template>
