import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
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

const ShopSettingController = { edit, update, destroyLogo }

export default ShopSettingController