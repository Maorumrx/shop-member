import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::store
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:65
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
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:65
* @route '/member/line/login'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::store
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:65
* @route '/member/line/login'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::store
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:65
* @route '/member/line/login'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::store
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:65
* @route '/member/line/login'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::submitCode
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:161
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
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:161
* @route '/member/line/submit-code'
*/
submitCode.url = (options?: RouteQueryOptions) => {
    return submitCode.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::submitCode
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:161
* @route '/member/line/submit-code'
*/
submitCode.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: submitCode.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::submitCode
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:161
* @route '/member/line/submit-code'
*/
const submitCodeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: submitCode.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::submitCode
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:161
* @route '/member/line/submit-code'
*/
submitCodeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: submitCode.url(options),
    method: 'post',
})

submitCode.form = submitCodeForm

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::createNew
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:209
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
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:209
* @route '/member/line/create-new'
*/
createNew.url = (options?: RouteQueryOptions) => {
    return createNew.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::createNew
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:209
* @route '/member/line/create-new'
*/
createNew.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: createNew.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::createNew
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:209
* @route '/member/line/create-new'
*/
const createNewForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: createNew.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::createNew
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:209
* @route '/member/line/create-new'
*/
createNewForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: createNew.url(options),
    method: 'post',
})

createNew.form = createNewForm

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::destroy
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:278
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
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:278
* @route '/member/logout'
*/
destroy.url = (options?: RouteQueryOptions) => {
    return destroy.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::destroy
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:278
* @route '/member/logout'
*/
destroy.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: destroy.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::destroy
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:278
* @route '/member/logout'
*/
const destroyForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Auth\MemberLineLoginController::destroy
* @see app/Http/Controllers/Auth/MemberLineLoginController.php:278
* @route '/member/logout'
*/
destroyForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(options),
    method: 'post',
})

destroy.form = destroyForm

const MemberLineLoginController = { store, submitCode, createNew, destroy }

export default MemberLineLoginController