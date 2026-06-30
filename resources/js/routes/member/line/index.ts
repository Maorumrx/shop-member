import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::login
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:39
* @route '/member/line/login'
*/
export const login = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: login.url(options),
    method: 'post',
})

login.definition = {
    methods: ["post"],
    url: '/member/line/login',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::login
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:39
* @route '/member/line/login'
*/
login.url = (options?: RouteQueryOptions) => {
    return login.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::login
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:39
* @route '/member/line/login'
*/
login.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: login.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::login
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:39
* @route '/member/line/login'
*/
const loginForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: login.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::login
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:39
* @route '/member/line/login'
*/
loginForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: login.url(options),
    method: 'post',
})

login.form = loginForm

const line = {
    login: Object.assign(login, login),
}

export default line