<script setup lang="ts">
/**
 * settings/Shop — owner-only shop brand settings (shop name + logo).
 *
 * Mirrors settings/Profile structure: this page is auto-wrapped by
 * [AppLayout, SettingsLayout] (see app.ts `settings/` case), so it only renders
 * the inner content and declares breadcrumbs for the AppLayout header.
 *
 * The logo field uses a raw `<input type="file">` (the shadcn `Input` is a
 * v-model string control and can't carry a File). A newly picked file gets a
 * live `URL.createObjectURL` preview that supersedes the saved logo; submission
 * is multipart via `forceFormData`. On success we clear the picked file +
 * reset the native file input so the field reflects the saved state again. The
 * success flash toast fires globally (initializeFlashToast).
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    shop: {
        name: string | null;
        logoUrl: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'ตั้งค่าร้าน', href: '/settings/shop' }],
    },
});

const form = useForm<{
    shop_name: string;
    logo: File | null;
}>({
    shop_name: props.shop.name ?? '',
    logo: null,
});

/** Native file input ref so we can clear its value after a successful save. */
const fileInput = ref<HTMLInputElement | null>(null);

/** Object URL for the live preview of a newly picked file (revoked on swap/unmount). */
const previewUrl = ref<string | null>(null);

function releasePreview(): void {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
        previewUrl.value = null;
    }
}

function onFileChange(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    releasePreview();
    form.logo = file;
    previewUrl.value = file ? URL.createObjectURL(file) : null;
}

function submit(): void {
    form.post('/settings/shop', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            // Saved: clear the picked file + native input so the field falls back
            // to the (now updated) saved logo on the next render.
            releasePreview();
            form.logo = null;

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

function removeLogo(): void {
    router.delete('/settings/shop/logo', { preserveScroll: true });
}

onBeforeUnmount(releasePreview);
</script>

<template>
    <Head title="ตั้งค่าร้าน" />

    <h1 class="sr-only">ตั้งค่าร้าน</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="ตั้งค่าร้าน"
            description="ตั้งชื่อร้านและโลโก้ที่จะแสดงบนแถบด้านข้าง"
        />

        <form class="space-y-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="shop_name">ชื่อร้าน</Label>
                <Input
                    id="shop_name"
                    v-model="form.shop_name"
                    class="mt-1 block w-full"
                    name="shop_name"
                    required
                    maxlength="120"
                    placeholder="ชื่อร้าน"
                />
                <InputError class="mt-2" :message="form.errors.shop_name" />
            </div>

            <div class="grid gap-2">
                <Label for="logo">โลโก้</Label>

                <!-- Preview: a freshly picked file takes precedence over the saved logo. -->
                <div
                    v-if="previewUrl || props.shop.logoUrl"
                    class="flex items-center gap-4"
                >
                    <div
                        class="flex size-16 items-center justify-center overflow-hidden rounded-md border border-border bg-muted"
                    >
                        <img
                            :src="previewUrl ?? props.shop.logoUrl ?? ''"
                            :alt="form.shop_name || 'โลโก้ร้าน'"
                            class="size-16 object-contain"
                        />
                    </div>

                    <!-- Remove only the saved logo (a not-yet-saved pick is cleared via the input). -->
                    <Button
                        v-if="props.shop.logoUrl && !previewUrl"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="removeLogo"
                    >
                        ลบโลโก้
                    </Button>
                </div>

                <input
                    id="logo"
                    ref="fileInput"
                    type="file"
                    name="logo"
                    accept="image/jpeg,image/png,image/webp"
                    class="flex h-9 w-full min-w-0 cursor-pointer rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] outline-none file:mr-3 file:inline-flex file:h-7 file:cursor-pointer file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30"
                    @change="onFileChange"
                />
                <p class="text-xs text-muted-foreground">
                    รองรับ jpg, png, webp ขนาดไม่เกิน 2 MB
                </p>
                <InputError class="mt-2" :message="form.errors.logo" />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="update-shop-button"
                >
                    บันทึก
                </Button>
            </div>
        </form>
    </div>
</template>
