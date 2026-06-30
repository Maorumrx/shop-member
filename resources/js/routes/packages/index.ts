import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Admin\PackageController::index
* @see app/Http/Controllers/Admin/PackageController.php:33
* @route '/packages'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/packages',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Admin\PackageController::index
* @see app/Http/Controllers/Admin/PackageController.php:33
* @route '/packages'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\PackageController::index
* @see app/Http/Controllers/Admin/PackageController.php:33
* @route '/packages'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::index
* @see app/Http/Controllers/Admin/PackageController.php:33
* @route '/packages'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::index
* @see app/Http/Controllers/Admin/PackageController.php:33
* @route '/packages'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::index
* @see app/Http/Controllers/Admin/PackageController.php:33
* @route '/packages'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::index
* @see app/Http/Controllers/Admin/PackageController.php:33
* @route '/packages'
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
* @see \App\Http\Controllers\Admin\PackageController::create
* @see app/Http/Controllers/Admin/PackageController.php:49
* @route '/packages/create'
*/
export const create = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

create.definition = {
    methods: ["get","head"],
    url: '/packages/create',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Admin\PackageController::create
* @see app/Http/Controllers/Admin/PackageController.php:49
* @route '/packages/create'
*/
create.url = (options?: RouteQueryOptions) => {
    return create.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\PackageController::create
* @see app/Http/Controllers/Admin/PackageController.php:49
* @route '/packages/create'
*/
create.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::create
* @see app/Http/Controllers/Admin/PackageController.php:49
* @route '/packages/create'
*/
create.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: create.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::create
* @see app/Http/Controllers/Admin/PackageController.php:49
* @route '/packages/create'
*/
const createForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: create.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::create
* @see app/Http/Controllers/Admin/PackageController.php:49
* @route '/packages/create'
*/
createForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: create.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::create
* @see app/Http/Controllers/Admin/PackageController.php:49
* @route '/packages/create'
*/
createForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: create.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

create.form = createForm

/**
* @see \App\Http\Controllers\Admin\PackageController::store
* @see app/Http/Controllers/Admin/PackageController.php:59
* @route '/packages'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/packages',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Admin\PackageController::store
* @see app/Http/Controllers/Admin/PackageController.php:59
* @route '/packages'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\PackageController::store
* @see app/Http/Controllers/Admin/PackageController.php:59
* @route '/packages'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::store
* @see app/Http/Controllers/Admin/PackageController.php:59
* @route '/packages'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::store
* @see app/Http/Controllers/Admin/PackageController.php:59
* @route '/packages'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\Admin\PackageController::edit
* @see app/Http/Controllers/Admin/PackageController.php:85
* @route '/packages/{package}/edit'
*/
export const edit = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(args, options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/packages/{package}/edit',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Admin\PackageController::edit
* @see app/Http/Controllers/Admin/PackageController.php:85
* @route '/packages/{package}/edit'
*/
edit.url = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { package: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { package: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            package: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        package: typeof args.package === 'object'
        ? args.package.id
        : args.package,
    }

    return edit.definition.url
            .replace('{package}', parsedArgs.package.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\PackageController::edit
* @see app/Http/Controllers/Admin/PackageController.php:85
* @route '/packages/{package}/edit'
*/
edit.get = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::edit
* @see app/Http/Controllers/Admin/PackageController.php:85
* @route '/packages/{package}/edit'
*/
edit.head = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::edit
* @see app/Http/Controllers/Admin/PackageController.php:85
* @route '/packages/{package}/edit'
*/
const editForm = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::edit
* @see app/Http/Controllers/Admin/PackageController.php:85
* @route '/packages/{package}/edit'
*/
editForm.get = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::edit
* @see app/Http/Controllers/Admin/PackageController.php:85
* @route '/packages/{package}/edit'
*/
editForm.head = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

edit.form = editForm

/**
* @see \App\Http\Controllers\Admin\PackageController::update
* @see app/Http/Controllers/Admin/PackageController.php:117
* @route '/packages/{package}'
*/
export const update = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/packages/{package}',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Admin\PackageController::update
* @see app/Http/Controllers/Admin/PackageController.php:117
* @route '/packages/{package}'
*/
update.url = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { package: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { package: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            package: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        package: typeof args.package === 'object'
        ? args.package.id
        : args.package,
    }

    return update.definition.url
            .replace('{package}', parsedArgs.package.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\PackageController::update
* @see app/Http/Controllers/Admin/PackageController.php:117
* @route '/packages/{package}'
*/
update.put = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::update
* @see app/Http/Controllers/Admin/PackageController.php:117
* @route '/packages/{package}'
*/
const updateForm = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::update
* @see app/Http/Controllers/Admin/PackageController.php:117
* @route '/packages/{package}'
*/
updateForm.put = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \App\Http\Controllers\Admin\PackageController::destroy
* @see app/Http/Controllers/Admin/PackageController.php:145
* @route '/packages/{package}'
*/
export const destroy = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/packages/{package}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Admin\PackageController::destroy
* @see app/Http/Controllers/Admin/PackageController.php:145
* @route '/packages/{package}'
*/
destroy.url = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { package: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { package: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            package: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        package: typeof args.package === 'object'
        ? args.package.id
        : args.package,
    }

    return destroy.definition.url
            .replace('{package}', parsedArgs.package.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\PackageController::destroy
* @see app/Http/Controllers/Admin/PackageController.php:145
* @route '/packages/{package}'
*/
destroy.delete = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::destroy
* @see app/Http/Controllers/Admin/PackageController.php:145
* @route '/packages/{package}'
*/
const destroyForm = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::destroy
* @see app/Http/Controllers/Admin/PackageController.php:145
* @route '/packages/{package}'
*/
destroyForm.delete = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \App\Http\Controllers\Admin\PackageController::toggle
* @see app/Http/Controllers/Admin/PackageController.php:158
* @route '/packages/{package}/toggle'
*/
export const toggle = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggle.url(args, options),
    method: 'patch',
})

toggle.definition = {
    methods: ["patch"],
    url: '/packages/{package}/toggle',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Admin\PackageController::toggle
* @see app/Http/Controllers/Admin/PackageController.php:158
* @route '/packages/{package}/toggle'
*/
toggle.url = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { package: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { package: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            package: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        package: typeof args.package === 'object'
        ? args.package.id
        : args.package,
    }

    return toggle.definition.url
            .replace('{package}', parsedArgs.package.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\PackageController::toggle
* @see app/Http/Controllers/Admin/PackageController.php:158
* @route '/packages/{package}/toggle'
*/
toggle.patch = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggle.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::toggle
* @see app/Http/Controllers/Admin/PackageController.php:158
* @route '/packages/{package}/toggle'
*/
const toggleForm = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: toggle.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\PackageController::toggle
* @see app/Http/Controllers/Admin/PackageController.php:158
* @route '/packages/{package}/toggle'
*/
toggleForm.patch = (args: { package: number | { id: number } } | [packageParam: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: toggle.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

toggle.form = toggleForm

const packages = {
    index: Object.assign(index, index),
    create: Object.assign(create, create),
    store: Object.assign(store, store),
    edit: Object.assign(edit, edit),
    update: Object.assign(update, update),
    destroy: Object.assign(destroy, destroy),
    toggle: Object.assign(toggle, toggle),
}

export default packages