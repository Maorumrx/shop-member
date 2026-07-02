import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::login
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:66
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
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:66
* @route '/member/line/login'
*/
login.url = (options?: RouteQueryOptions) => {
    return login.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::login
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:66
* @route '/member/line/login'
*/
login.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: login.url(options),
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

const line = {
    login: Object.assign(login, login),
    submitCode: Object.assign(submitCode, submitCode),
    createNew: Object.assign(createNew, createNew),
}

export default line