<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

// Shop brand is shared globally; `logoUrl` is null when no logo is set, in which
// case we fall back to the default AppLogoIcon mark.
// Guard against partial Inertia reloads that may omit the shared `shop` prop.
const shop = computed(
    () => usePage().props.shop ?? { name: '', logoUrl: null },
);
</script>

<template>
    <div
        v-if="shop.logoUrl"
        class="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-md bg-sidebar-primary"
    >
        <img
            :src="shop.logoUrl"
            :alt="shop.name"
            class="size-8 rounded-md object-contain"
        />
    </div>
    <div
        v-else
        class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
    >
        <AppLogoIcon class="size-5 fill-current text-white dark:text-black" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{
            shop.name
        }}</span>
    </div>
</template>
