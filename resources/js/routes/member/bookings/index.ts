import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Member\BookingController::index
* @see app/Http/Controllers/Member/BookingController.php:53
* @route '/member/bookings'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/member/bookings',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Member\BookingController::index
* @see app/Http/Controllers/Member/BookingController.php:53
* @route '/member/bookings'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Member\BookingController::index
* @see app/Http/Controllers/Member/BookingController.php:53
* @route '/member/bookings'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Member\BookingController::index
* @see app/Http/Controllers/Member/BookingController.php:53
* @route '/member/bookings'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Member\BookingController::index
* @see app/Http/Controllers/Member/BookingController.php:53
* @route '/member/bookings'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Member\BookingController::index
* @see app/Http/Controllers/Member/BookingController.php:53
* @route '/member/bookings'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Member\BookingController::index
* @see app/Http/Controllers/Member/BookingController.php:53
* @route '/member/bookings'
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
* @see \App\Http\Controllers\Member\BookingController::availability
* @see app/Http/Controllers/Member/BookingController.php:74
* @route '/member/bookings/availability'
*/
export const availability = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: availability.url(options),
    method: 'get',
})

availability.definition = {
    methods: ["get","head"],
    url: '/member/bookings/availability',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Member\BookingController::availability
* @see app/Http/Controllers/Member/BookingController.php:74
* @route '/member/bookings/availability'
*/
availability.url = (options?: RouteQueryOptions) => {
    return availability.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Member\BookingController::availability
* @see app/Http/Controllers/Member/BookingController.php:74
* @route '/member/bookings/availability'
*/
availability.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: availability.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Member\BookingController::availability
* @see app/Http/Controllers/Member/BookingController.php:74
* @route '/member/bookings/availability'
*/
availability.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: availability.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Member\BookingController::availability
* @see app/Http/Controllers/Member/BookingController.php:74
* @route '/member/bookings/availability'
*/
const availabilityForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: availability.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Member\BookingController::availability
* @see app/Http/Controllers/Member/BookingController.php:74
* @route '/member/bookings/availability'
*/
availabilityForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: availability.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Member\BookingController::availability
* @see app/Http/Controllers/Member/BookingController.php:74
* @route '/member/bookings/availability'
*/
availabilityForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: availability.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

availability.form = availabilityForm

/**
* @see \App\Http\Controllers\Member\BookingController::store
* @see app/Http/Controllers/Member/BookingController.php:95
* @route '/member/bookings'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/member/bookings',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Member\BookingController::store
* @see app/Http/Controllers/Member/BookingController.php:95
* @route '/member/bookings'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Member\BookingController::store
* @see app/Http/Controllers/Member/BookingController.php:95
* @route '/member/bookings'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Member\BookingController::store
* @see app/Http/Controllers/Member/BookingController.php:95
* @route '/member/bookings'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Member\BookingController::store
* @see app/Http/Controllers/Member/BookingController.php:95
* @route '/member/bookings'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\Member\BookingController::cancel
* @see app/Http/Controllers/Member/BookingController.php:131
* @route '/member/bookings/{booking}'
*/
export const cancel = (args: { booking: number | { id: number } } | [booking: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: cancel.url(args, options),
    method: 'delete',
})

cancel.definition = {
    methods: ["delete"],
    url: '/member/bookings/{booking}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Member\BookingController::cancel
* @see app/Http/Controllers/Member/BookingController.php:131
* @route '/member/bookings/{booking}'
*/
cancel.url = (args: { booking: number | { id: number } } | [booking: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { booking: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { booking: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            booking: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        booking: typeof args.booking === 'object'
        ? args.booking.id
        : args.booking,
    }

    return cancel.definition.url
            .replace('{booking}', parsedArgs.booking.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Member\BookingController::cancel
* @see app/Http/Controllers/Member/BookingController.php:131
* @route '/member/bookings/{booking}'
*/
cancel.delete = (args: { booking: number | { id: number } } | [booking: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: cancel.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Member\BookingController::cancel
* @see app/Http/Controllers/Member/BookingController.php:131
* @route '/member/bookings/{booking}'
*/
const cancelForm = (args: { booking: number | { id: number } } | [booking: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: cancel.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Member\BookingController::cancel
* @see app/Http/Controllers/Member/BookingController.php:131
* @route '/member/bookings/{booking}'
*/
cancelForm.delete = (args: { booking: number | { id: number } } | [booking: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: cancel.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

cancel.form = cancelForm

const bookings = {
    index: Object.assign(index, index),
    availability: Object.assign(availability, availability),
    store: Object.assign(store, store),
    cancel: Object.assign(cancel, cancel),
}

export default bookings