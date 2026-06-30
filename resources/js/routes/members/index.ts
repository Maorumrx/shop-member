import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
import purchases from './purchases'
/**
* @see \App\Http\Controllers\Admin\MemberController::index
* @see app/Http/Controllers/Admin/MemberController.php:37
* @route '/members'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/members',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Admin\MemberController::index
* @see app/Http/Controllers/Admin/MemberController.php:37
* @route '/members'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\MemberController::index
* @see app/Http/Controllers/Admin/MemberController.php:37
* @route '/members'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\MemberController::index
* @see app/Http/Controllers/Admin/MemberController.php:37
* @route '/members'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Admin\MemberController::index
* @see app/Http/Controllers/Admin/MemberController.php:37
* @route '/members'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\MemberController::index
* @see app/Http/Controllers/Admin/MemberController.php:37
* @route '/members'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\MemberController::index
* @see app/Http/Controllers/Admin/MemberController.php:37
* @route '/members'
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
* @see \App\Http\Controllers\Admin\MemberController::store
* @see app/Http/Controllers/Admin/MemberController.php:74
* @route '/members'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/members',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Admin\MemberController::store
* @see app/Http/Controllers/Admin/MemberController.php:74
* @route '/members'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\MemberController::store
* @see app/Http/Controllers/Admin/MemberController.php:74
* @route '/members'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\MemberController::store
* @see app/Http/Controllers/Admin/MemberController.php:74
* @route '/members'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\MemberController::store
* @see app/Http/Controllers/Admin/MemberController.php:74
* @route '/members'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\Admin\MemberController::show
* @see app/Http/Controllers/Admin/MemberController.php:105
* @route '/members/{member}'
*/
export const show = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/members/{member}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Admin\MemberController::show
* @see app/Http/Controllers/Admin/MemberController.php:105
* @route '/members/{member}'
*/
show.url = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{member}', parsedArgs.member.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\MemberController::show
* @see app/Http/Controllers/Admin/MemberController.php:105
* @route '/members/{member}'
*/
show.get = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\MemberController::show
* @see app/Http/Controllers/Admin/MemberController.php:105
* @route '/members/{member}'
*/
show.head = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Admin\MemberController::show
* @see app/Http/Controllers/Admin/MemberController.php:105
* @route '/members/{member}'
*/
const showForm = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\MemberController::show
* @see app/Http/Controllers/Admin/MemberController.php:105
* @route '/members/{member}'
*/
showForm.get = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\MemberController::show
* @see app/Http/Controllers/Admin/MemberController.php:105
* @route '/members/{member}'
*/
showForm.head = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

show.form = showForm

/**
* @see \App\Http\Controllers\Admin\MemberController::update
* @see app/Http/Controllers/Admin/MemberController.php:87
* @route '/members/{member}'
*/
export const update = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/members/{member}',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Admin\MemberController::update
* @see app/Http/Controllers/Admin/MemberController.php:87
* @route '/members/{member}'
*/
update.url = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{member}', parsedArgs.member.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\MemberController::update
* @see app/Http/Controllers/Admin/MemberController.php:87
* @route '/members/{member}'
*/
update.put = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Admin\MemberController::update
* @see app/Http/Controllers/Admin/MemberController.php:87
* @route '/members/{member}'
*/
const updateForm = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\MemberController::update
* @see app/Http/Controllers/Admin/MemberController.php:87
* @route '/members/{member}'
*/
updateForm.put = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

update.form = updateForm

const members = {
    index: Object.assign(index, index),
    store: Object.assign(store, store),
    show: Object.assign(show, show),
    update: Object.assign(update, update),
    purchases: Object.assign(purchases, purchases),
}

export default members