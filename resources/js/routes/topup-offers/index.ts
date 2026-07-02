import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Admin\TopupOfferController::index
* @see app/Http/Controllers/Admin/TopupOfferController.php:30
* @route '/topup-offers'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/topup-offers',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::index
* @see app/Http/Controllers/Admin/TopupOfferController.php:30
* @route '/topup-offers'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::index
* @see app/Http/Controllers/Admin/TopupOfferController.php:30
* @route '/topup-offers'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::index
* @see app/Http/Controllers/Admin/TopupOfferController.php:30
* @route '/topup-offers'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::index
* @see app/Http/Controllers/Admin/TopupOfferController.php:30
* @route '/topup-offers'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::index
* @see app/Http/Controllers/Admin/TopupOfferController.php:30
* @route '/topup-offers'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::index
* @see app/Http/Controllers/Admin/TopupOfferController.php:30
* @route '/topup-offers'
*/
indexForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

index.form = indexForm

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::store
* @see app/Http/Controllers/Admin/TopupOfferController.php:43
* @route '/topup-offers'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/topup-offers',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::store
* @see app/Http/Controllers/Admin/TopupOfferController.php:43
* @route '/topup-offers'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::store
* @see app/Http/Controllers/Admin/TopupOfferController.php:43
* @route '/topup-offers'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::store
* @see app/Http/Controllers/Admin/TopupOfferController.php:43
* @route '/topup-offers'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::store
* @see app/Http/Controllers/Admin/TopupOfferController.php:43
* @route '/topup-offers'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::update
* @see app/Http/Controllers/Admin/TopupOfferController.php:55
* @route '/topup-offers/{topupOffer}'
*/
export const update = (args: { topupOffer: number | { id: number } } | [topupOffer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/topup-offers/{topupOffer}',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::update
* @see app/Http/Controllers/Admin/TopupOfferController.php:55
* @route '/topup-offers/{topupOffer}'
*/
update.url = (args: { topupOffer: number | { id: number } } | [topupOffer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { topupOffer: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { topupOffer: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            topupOffer: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        topupOffer: typeof args.topupOffer === 'object'
        ? args.topupOffer.id
        : args.topupOffer,
    }

    return update.definition.url
            .replace('{topupOffer}', parsedArgs.topupOffer.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::update
* @see app/Http/Controllers/Admin/TopupOfferController.php:55
* @route '/topup-offers/{topupOffer}'
*/
update.put = (args: { topupOffer: number | { id: number } } | [topupOffer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::update
* @see app/Http/Controllers/Admin/TopupOfferController.php:55
* @route '/topup-offers/{topupOffer}'
*/
const updateForm = (args: { topupOffer: number | { id: number } } | [topupOffer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::update
* @see app/Http/Controllers/Admin/TopupOfferController.php:55
* @route '/topup-offers/{topupOffer}'
*/
updateForm.put = (args: { topupOffer: number | { id: number } } | [topupOffer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

update.form = updateForm

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::destroy
* @see app/Http/Controllers/Admin/TopupOfferController.php:68
* @route '/topup-offers/{topupOffer}'
*/
export const destroy = (args: { topupOffer: number | { id: number } } | [topupOffer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/topup-offers/{topupOffer}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::destroy
* @see app/Http/Controllers/Admin/TopupOfferController.php:68
* @route '/topup-offers/{topupOffer}'
*/
destroy.url = (args: { topupOffer: number | { id: number } } | [topupOffer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { topupOffer: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { topupOffer: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            topupOffer: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        topupOffer: typeof args.topupOffer === 'object'
        ? args.topupOffer.id
        : args.topupOffer,
    }

    return destroy.definition.url
            .replace('{topupOffer}', parsedArgs.topupOffer.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::destroy
* @see app/Http/Controllers/Admin/TopupOfferController.php:68
* @route '/topup-offers/{topupOffer}'
*/
destroy.delete = (args: { topupOffer: number | { id: number } } | [topupOffer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::destroy
* @see app/Http/Controllers/Admin/TopupOfferController.php:68
* @route '/topup-offers/{topupOffer}'
*/
const destroyForm = (args: { topupOffer: number | { id: number } } | [topupOffer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::destroy
* @see app/Http/Controllers/Admin/TopupOfferController.php:68
* @route '/topup-offers/{topupOffer}'
*/
destroyForm.delete = (args: { topupOffer: number | { id: number } } | [topupOffer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::toggle
* @see app/Http/Controllers/Admin/TopupOfferController.php:80
* @route '/topup-offers/{topupOffer}/toggle'
*/
export const toggle = (args: { topupOffer: number | { id: number } } | [topupOffer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggle.url(args, options),
    method: 'patch',
})

toggle.definition = {
    methods: ["patch"],
    url: '/topup-offers/{topupOffer}/toggle',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::toggle
* @see app/Http/Controllers/Admin/TopupOfferController.php:80
* @route '/topup-offers/{topupOffer}/toggle'
*/
toggle.url = (args: { topupOffer: number | { id: number } } | [topupOffer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { topupOffer: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { topupOffer: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            topupOffer: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        topupOffer: typeof args.topupOffer === 'object'
        ? args.topupOffer.id
        : args.topupOffer,
    }

    return toggle.definition.url
            .replace('{topupOffer}', parsedArgs.topupOffer.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::toggle
* @see app/Http/Controllers/Admin/TopupOfferController.php:80
* @route '/topup-offers/{topupOffer}/toggle'
*/
toggle.patch = (args: { topupOffer: number | { id: number } } | [topupOffer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggle.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::toggle
* @see app/Http/Controllers/Admin/TopupOfferController.php:80
* @route '/topup-offers/{topupOffer}/toggle'
*/
const toggleForm = (args: { topupOffer: number | { id: number } } | [topupOffer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: toggle.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\TopupOfferController::toggle
* @see app/Http/Controllers/Admin/TopupOfferController.php:80
* @route '/topup-offers/{topupOffer}/toggle'
*/
toggleForm.patch = (args: { topupOffer: number | { id: number } } | [topupOffer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: toggle.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

toggle.form = toggleForm

const topupOffers = {
    index: Object.assign(index, index),
    store: Object.assign(store, store),
    update: Object.assign(update, update),
    destroy: Object.assign(destroy, destroy),
    toggle: Object.assign(toggle, toggle),
}

export default topupOffers