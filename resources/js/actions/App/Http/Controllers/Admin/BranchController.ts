import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Admin\BranchController::index
* @see app/Http/Controllers/Admin/BranchController.php:33
* @route '/branches'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/branches',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Admin\BranchController::index
* @see app/Http/Controllers/Admin/BranchController.php:33
* @route '/branches'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\BranchController::index
* @see app/Http/Controllers/Admin/BranchController.php:33
* @route '/branches'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\BranchController::index
* @see app/Http/Controllers/Admin/BranchController.php:33
* @route '/branches'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Admin\BranchController::store
* @see app/Http/Controllers/Admin/BranchController.php:58
* @route '/branches'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/branches',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Admin\BranchController::store
* @see app/Http/Controllers/Admin/BranchController.php:58
* @route '/branches'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\BranchController::store
* @see app/Http/Controllers/Admin/BranchController.php:58
* @route '/branches'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\BranchController::update
* @see app/Http/Controllers/Admin/BranchController.php:67
* @route '/branches/{branch}'
*/
export const update = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/branches/{branch}',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Admin\BranchController::update
* @see app/Http/Controllers/Admin/BranchController.php:67
* @route '/branches/{branch}'
*/
update.url = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { branch: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { branch: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            branch: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        branch: typeof args.branch === 'object'
        ? args.branch.id
        : args.branch,
    }

    return update.definition.url
            .replace('{branch}', parsedArgs.branch.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\BranchController::update
* @see app/Http/Controllers/Admin/BranchController.php:67
* @route '/branches/{branch}'
*/
update.put = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Admin\BranchController::destroy
* @see app/Http/Controllers/Admin/BranchController.php:82
* @route '/branches/{branch}'
*/
export const destroy = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/branches/{branch}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Admin\BranchController::destroy
* @see app/Http/Controllers/Admin/BranchController.php:82
* @route '/branches/{branch}'
*/
destroy.url = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { branch: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { branch: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            branch: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        branch: typeof args.branch === 'object'
        ? args.branch.id
        : args.branch,
    }

    return destroy.definition.url
            .replace('{branch}', parsedArgs.branch.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\BranchController::destroy
* @see app/Http/Controllers/Admin/BranchController.php:82
* @route '/branches/{branch}'
*/
destroy.delete = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Admin\BranchController::toggle
* @see app/Http/Controllers/Admin/BranchController.php:105
* @route '/branches/{branch}/toggle'
*/
export const toggle = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggle.url(args, options),
    method: 'patch',
})

toggle.definition = {
    methods: ["patch"],
    url: '/branches/{branch}/toggle',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Admin\BranchController::toggle
* @see app/Http/Controllers/Admin/BranchController.php:105
* @route '/branches/{branch}/toggle'
*/
toggle.url = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { branch: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { branch: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            branch: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        branch: typeof args.branch === 'object'
        ? args.branch.id
        : args.branch,
    }

    return toggle.definition.url
            .replace('{branch}', parsedArgs.branch.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\BranchController::toggle
* @see app/Http/Controllers/Admin/BranchController.php:105
* @route '/branches/{branch}/toggle'
*/
toggle.patch = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggle.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Admin\BranchController::updateBookingSettings
* @see app/Http/Controllers/Admin/BranchController.php:127
* @route '/branches/{branch}/booking-settings'
*/
export const updateBookingSettings = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateBookingSettings.url(args, options),
    method: 'put',
})

updateBookingSettings.definition = {
    methods: ["put"],
    url: '/branches/{branch}/booking-settings',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Admin\BranchController::updateBookingSettings
* @see app/Http/Controllers/Admin/BranchController.php:127
* @route '/branches/{branch}/booking-settings'
*/
updateBookingSettings.url = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { branch: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { branch: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            branch: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        branch: typeof args.branch === 'object'
        ? args.branch.id
        : args.branch,
    }

    return updateBookingSettings.definition.url
            .replace('{branch}', parsedArgs.branch.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\BranchController::updateBookingSettings
* @see app/Http/Controllers/Admin/BranchController.php:127
* @route '/branches/{branch}/booking-settings'
*/
updateBookingSettings.put = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateBookingSettings.url(args, options),
    method: 'put',
})

const BranchController = { index, store, update, destroy, toggle, updateBookingSettings }

export default BranchController