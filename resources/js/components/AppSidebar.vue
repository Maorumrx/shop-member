<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CalendarDays,
    LayoutGrid,
    Scissors,
    Store,
    UsersRound,
    Wallet,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'บริการ',
        href: '/services',
        icon: Scissors,
    },
    {
        title: 'แพ็กเกจเติมเครดิต',
        href: '/topup-offers',
        icon: Wallet,
    },
    {
        title: 'สาขา',
        href: '/branches',
        icon: Store,
    },
    {
        title: 'สมาชิก',
        href: '/members',
        icon: UsersRound,
    },
    {
        title: 'การจอง',
        href: '/bookings',
        icon: CalendarDays,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
