/** Roles a shop user can hold. `owner` gates shop-level settings (e.g. branding). */
export type UserRole = 'owner' | 'staff';

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    role: UserRole;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
