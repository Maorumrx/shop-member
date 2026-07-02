import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::store
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:66
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
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:66
* @route '/member/line/login'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::store
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:66
* @route '/member/line/login'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::submitCode
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:162
* @route '/member/line/submit-code'
*/
export const submitCode = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: submitCode.url(options),
    method: 'post',
})

submitCode.definition = {
    methods: ["post"],
    url: '/member/line/submit-code',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::submitCode
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:162
* @route '/member/line/submit-code'
*/
submitCode.url = (options?: RouteQueryOptions) => {
    return submitCode.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::submitCode
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:162
* @route '/member/line/submit-code'
*/
submitCode.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: submitCode.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::createNew
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:214
* @route '/member/line/create-new'
*/
export const createNew = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: createNew.url(options),
    method: 'post',
})

createNew.definition = {
    methods: ["post"],
    url: '/member/line/create-new',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::createNew
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:214
* @route '/member/line/create-new'
*/
createNew.url = (options?: RouteQueryOptions) => {
    return createNew.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::createNew
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:214
* @route '/member/line/create-new'
*/
createNew.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: createNew.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::destroy
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:287
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
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:287
* @route '/member/logout'
*/
destroy.url = (options?: RouteQueryOptions) => {
    return destroy.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::destroy
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:287
* @route '/member/logout'
*/
destroy.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: destroy.url(options),
    method: 'post',
})

const MemberLineLoginController = { store, submitCode, createNew, destroy }

export default MemberLineLoginController