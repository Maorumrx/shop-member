import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::store
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:39
* @route '/member/line/login'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/member/line/login',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::store
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:39
* @route '/member/line/login'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::store
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:39
* @route '/member/line/login'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::store
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:39
* @route '/member/line/login'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::store
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:39
* @route '/member/line/login'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::destroy
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:117
* @route '/member/logout'
*/
export const destroy = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: destroy.url(options),
    method: 'post',
})

destroy.definition = {
    methods: ["post"],
    url: '/member/logout',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::destroy
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:117
* @route '/member/logout'
*/
destroy.url = (options?: RouteQueryOptions) => {
    return destroy.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::destroy
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:117
* @route '/member/logout'
*/
destroy.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: destroy.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::destroy
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:117
* @route '/member/logout'
*/
const destroyForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::destroy
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:117
* @route '/member/logout'
*/
destroyForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(options),
    method: 'post',
})

destroy.form = destroyForm

const MemberLineLoginController = { store, destroy }

export default MemberLineLoginController