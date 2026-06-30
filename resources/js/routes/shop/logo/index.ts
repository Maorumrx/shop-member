import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Admin\ShopSettingController::destroy
* @see app/Http/Controllers/Admin/ShopSettingController.php:74
* @route '/settings/shop/logo'
*/
export const destroy = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/settings/shop/logo',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::destroy
* @see app/Http/Controllers/Admin/ShopSettingController.php:74
* @route '/settings/shop/logo'
*/
destroy.url = (options?: RouteQueryOptions) => {
    return destroy.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::destroy
* @see app/Http/Controllers/Admin/ShopSettingController.php:74
* @route '/settings/shop/logo'
*/
destroy.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::destroy
* @see app/Http/Controllers/Admin/ShopSettingController.php:74
* @route '/settings/shop/logo'
*/
const destroyForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\ShopSettingController::destroy
* @see app/Http/Controllers/Admin/ShopSettingController.php:74
* @route '/settings/shop/logo'
*/
destroyForm.delete = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

const logo = {
    destroy: Object.assign(destroy, destroy),
}

export default logo