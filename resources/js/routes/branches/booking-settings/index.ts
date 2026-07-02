import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Admin\BranchController::update
* @see app/Http/Controllers/Admin/BranchController.php:127
* @route '/branches/{branch}/booking-settings'
*/
export const update = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/branches/{branch}/booking-settings',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Admin\BranchController::update
* @see app/Http/Controllers/Admin/BranchController.php:127
* @route '/branches/{branch}/booking-settings'
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
* @see app/Http/Controllers/Admin/BranchController.php:127
* @route '/branches/{branch}/booking-settings'
*/
update.put = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

const bookingSettings = {
    update: Object.assign(update, update),
}

export default bookingSettings