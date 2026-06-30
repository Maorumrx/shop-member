import type { Auth } from '@/types/auth';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            /** PUBLIC LINE LIFF app id (services.line.liff_id). Null until configured. */
            lineLiffId: string | null;
            sidebarOpen: boolean;
            /**
             * Shop brand, shared globally (read by the sidebar/header `AppLogo`).
             * `name` is the already-resolved display name (server applies the
             * `config('app.name')` fallback); `logoUrl` is null when no logo set.
             */
            shop: {
                name: string;
                logoUrl: string | null;
            };
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
