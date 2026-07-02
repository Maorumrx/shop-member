import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
import line from './line'
import bookings from './bookings'
/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/member'
*/
export const login = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: login.url(options),
    method: 'get',
})

login.definition = {
    methods: ["get","head"],
    url: '/member',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/member'
*/
login.url = (options?: RouteQueryOptions) => {
    return login.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/member'
*/
login.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: login.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/member'
*/
login.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: login.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::logout
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:287
* @route '/member/logout'
*/
export const logout = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: logout.url(options),
    method: 'post',
})

logout.definition = {
    methods: ["post"],
    url: '/member/logout',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::logout
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:287
* @route '/member/logout'
*/
logout.url = (options?: RouteQueryOptions) => {
    return logout.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::logout
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:287
* @route '/member/logout'
*/
logout.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: logout.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Member\DashboardController::dashboard
* @see app/Http/Controllers/Member/DashboardController.php:35
* @route '/member/dashboard'
*/
export const dashboard = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: dashboard.url(options),
    method: 'get',
})

dashboard.definition = {
    methods: ["get","head"],
    url: '/member/dashboard',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Member\DashboardController::dashboard
* @see app/Http/Controllers/Member/DashboardController.php:35
* @route '/member/dashboard'
*/
dashboard.url = (options?: RouteQueryOptions) => {
    return dashboard.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Member\DashboardController::dashboard
* @see app/Http/Controllers/Member/DashboardController.php:35
* @route '/member/dashboard'
*/
dashboard.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: dashboard.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Member\DashboardController::dashboard
* @see app/Http/Controllers/Member/DashboardController.php:35
* @route '/member/dashboard'
*/
dashboard.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: dashboard.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::devLogin
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:125
* @route '/member/dev-login/{member}'
*/
export const devLogin = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: devLogin.url(args, options),
    method: 'get',
})

devLogin.definition = {
    methods: ["get","head"],
    url: '/member/dev-login/{member}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::devLogin
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:125
* @route '/member/dev-login/{member}'
*/
devLogin.url = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { member: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { member: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            member: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        member: typeof args.member === 'object'
        ? args.member.id
        : args.member,
    }

    return devLogin.definition.url
            .replace('{member}', parsedArgs.member.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::devLogin
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:125
* @route '/member/dev-login/{member}'
*/
devLogin.get = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: devLogin.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::devLogin
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:125
* @route '/member/dev-login/{member}'
*/
devLogin.head = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: devLogin.url(args, options),
    method: 'head',
})

const member = {
    login: Object.assign(login, login),
    line: Object.assign(line, line),
    logout: Object.assign(logout, logout),
    dashboard: Object.assign(dashboard, dashboard),
    bookings: Object.assign(bookings, bookings),
    devLogin: Object.assign(devLogin, devLogin),
}

export default member