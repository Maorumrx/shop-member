import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
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
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/member'
*/
const loginForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: login.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/member'
*/
loginForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: login.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/member'
*/
loginForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: login.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

login.form = loginForm

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::logout
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:278
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
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:278
* @route '/member/logout'
*/
logout.url = (options?: RouteQueryOptions) => {
    return logout.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::logout
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:278
* @route '/member/logout'
*/
logout.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: logout.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::logout
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:278
* @route '/member/logout'
*/
const logoutForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: logout.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::logout
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:278
* @route '/member/logout'
*/
logoutForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: logout.url(options),
    method: 'post',
})

logout.form = logoutForm

/**
* @see \App\Http\Controllers\Member\DashboardController::dashboard
* @see app/Http/Controllers/Member/DashboardController.php:34
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
* @see app/Http/Controllers/Member/DashboardController.php:34
* @route '/member/dashboard'
*/
dashboard.url = (options?: RouteQueryOptions) => {
    return dashboard.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Member\DashboardController::dashboard
* @see app/Http/Controllers/Member/DashboardController.php:34
* @route '/member/dashboard'
*/
dashboard.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: dashboard.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Member\DashboardController::dashboard
* @see app/Http/Controllers/Member/DashboardController.php:34
* @route '/member/dashboard'
*/
dashboard.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: dashboard.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Member\DashboardController::dashboard
* @see app/Http/Controllers/Member/DashboardController.php:34
* @route '/member/dashboard'
*/
const dashboardForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: dashboard.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Member\DashboardController::dashboard
* @see app/Http/Controllers/Member/DashboardController.php:34
* @route '/member/dashboard'
*/
dashboardForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: dashboard.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Member\DashboardController::dashboard
* @see app/Http/Controllers/Member/DashboardController.php:34
* @route '/member/dashboard'
*/
dashboardForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: dashboard.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

dashboard.form = dashboardForm

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::devLogin
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:67
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
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:67
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
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:67
* @route '/member/dev-login/{member}'
*/
devLogin.get = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: devLogin.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::devLogin
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:67
* @route '/member/dev-login/{member}'
*/
devLogin.head = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: devLogin.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::devLogin
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:67
* @route '/member/dev-login/{member}'
*/
const devLoginForm = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: devLogin.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::devLogin
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:67
* @route '/member/dev-login/{member}'
*/
devLoginForm.get = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: devLogin.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::devLogin
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:67
* @route '/member/dev-login/{member}'
*/
devLoginForm.head = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: devLogin.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

devLogin.form = devLoginForm

const member = {
    login: Object.assign(login, login),
    line: Object.assign(line, line),
    logout: Object.assign(logout, logout),
    dashboard: Object.assign(dashboard, dashboard),
    bookings: Object.assign(bookings, bookings),
    devLogin: Object.assign(devLogin, devLogin),
}

export default member