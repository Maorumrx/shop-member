import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Admin\ShopSettingController::edit
* @see app/Http/Controllers/Admin/ShopSettingController.php:29
* @route '/settings/shop'
*/
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/shop',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::edit
* @see app/Http/Controllers/Admin/ShopSettingController.php:29
* @route '/settings/shop'
*/
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::edit
* @see app/Http/Controllers/Admin/ShopSettingController.php:29
* @route '/settings/shop'
*/
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::edit
* @see app/Http/Controllers/Admin/ShopSettingController.php:29
* @route '/settings/shop'
*/
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::edit
* @see app/Http/Controllers/Admin/ShopSettingController.php:29
* @route '/settings/shop'
*/
const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::edit
* @see app/Http/Controllers/Admin/ShopSettingController.php:29
* @route '/settings/shop'
*/
editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::edit
* @see app/Http/Controllers/Admin/ShopSettingController.php:29
* @route '/settings/shop'
*/
editForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

edit.form = editForm

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::update
* @see app/Http/Controllers/Admin/ShopSettingController.php:46
* @route '/settings/shop'
*/
export const update = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: update.url(options),
    method: 'post',
})

update.definition = {
    methods: ["post"],
    url: '/settings/shop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::update
* @see app/Http/Controllers/Admin/ShopSettingController.php:46
* @route '/settings/shop'
*/
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::update
* @see app/Http/Controllers/Admin/ShopSettingController.php:46
* @route '/settings/shop'
*/
update.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: update.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::update
* @see app/Http/Controllers/Admin/ShopSettingController.php:46
* @route '/settings/shop'
*/
const updateForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::update
* @see app/Http/Controllers/Admin/ShopSettingController.php:46
* @route '/settings/shop'
*/
updateForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(options),
    method: 'post',
})

update.form = updateForm

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::destroyLogo
* @see app/Http/Controllers/Admin/ShopSettingController.php:74
* @route '/settings/shop/logo'
*/
export const destroyLogo = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroyLogo.url(options),
    method: 'delete',
})

destroyLogo.definition = {
    methods: ["delete"],
    url: '/settings/shop/logo',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::destroyLogo
* @see app/Http/Controllers/Admin/ShopSettingController.php:74
* @route '/settings/shop/logo'
*/
destroyLogo.url = (options?: RouteQueryOptions) => {
    return destroyLogo.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::destroyLogo
* @see app/Http/Controllers/Admin/ShopSettingController.php:74
* @route '/settings/shop/logo'
*/
destroyLogo.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroyLogo.url(options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::destroyLogo
* @see app/Http/Controllers/Admin/ShopSettingController.php:74
* @route '/settings/shop/logo'
*/
const destroyLogoForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroyLogo.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::destroyLogo
* @see app/Http/Controllers/Admin/ShopSettingController.php:74
* @route '/settings/shop/logo'
*/
destroyLogoForm.delete = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroyLogo.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroyLogo.form = destroyLogoForm

const ShopSettingController = { edit, update, destroyLogo }

export default ShopSettingController