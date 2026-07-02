import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Admin\BookingController::index
* @see app/Http/Controllers/Admin/BookingController.php:58
* @route '/bookings'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/bookings',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Admin\BookingController::index
* @see app/Http/Controllers/Admin/BookingController.php:58
* @route '/bookings'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\BookingController::index
* @see app/Http/Controllers/Admin/BookingController.php:58
* @route '/bookings'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Admin\BookingController::index
* @see app/Http/Controllers/Admin/BookingController.php:58
* @route '/bookings'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Admin\BookingController::store
* @see app/Http/Controllers/Admin/BookingController.php:92
* @route '/bookings'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/bookings',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Admin\BookingController::store
* @see app/Http/Controllers/Admin/BookingController.php:92
* @route '/bookings'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\BookingController::store
* @see app/Http/Controllers/Admin/BookingController.php:92
* @route '/bookings'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\BookingController::checkIn
* @see app/Http/Controllers/Admin/BookingController.php:133
* @route '/bookings/{booking}/check-in'
*/
export const checkIn = (args: { booking: number | { id: number } } | [booking: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: checkIn.url(args, options),
    method: 'post',
})

checkIn.definition = {
    methods: ["post"],
    url: '/bookings/{booking}/check-in',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Admin\BookingController::checkIn
* @see app/Http/Controllers/Admin/BookingController.php:133
* @route '/bookings/{booking}/check-in'
*/
checkIn.url = (args: { booking: number | { id: number } } | [booking: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return checkIn.definition.url
            .replace('{booking}', parsedArgs.booking.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\BookingController::checkIn
* @see app/Http/Controllers/Admin/BookingController.php:133
* @route '/bookings/{booking}/check-in'
*/
checkIn.post = (args: { booking: number | { id: number } } | [booking: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: checkIn.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\BookingController::noShow
* @see app/Http/Controllers/Admin/BookingController.php:179
* @route '/bookings/{booking}/no-show'
*/
export const noShow = (args: { booking: number | { id: number } } | [booking: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: noShow.url(args, options),
    method: 'post',
})

noShow.definition = {
    methods: ["post"],
    url: '/bookings/{booking}/no-show',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Admin\BookingController::noShow
* @see app/Http/Controllers/Admin/BookingController.php:179
* @route '/bookings/{booking}/no-show'
*/
noShow.url = (args: { booking: number | { id: number } } | [booking: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return noShow.definition.url
            .replace('{booking}', parsedArgs.booking.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\BookingController::noShow
* @see app/Http/Controllers/Admin/BookingController.php:179
* @route '/bookings/{booking}/no-show'
*/
noShow.post = (args: { booking: number | { id: number } } | [booking: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: noShow.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\BookingController::cancel
* @see app/Http/Controllers/Admin/BookingController.php:201
* @route '/bookings/{booking}'
*/
export const cancel = (args: { booking: number | { id: number } } | [booking: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: cancel.url(args, options),
    method: 'delete',
})

cancel.definition = {
    methods: ["delete"],
    url: '/bookings/{booking}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Admin\BookingController::cancel
* @see app/Http/Controllers/Admin/BookingController.php:201
* @route '/bookings/{booking}'
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
* @see \App\Http\Controllers\Admin\BookingController::cancel
* @see app/Http/Controllers/Admin/BookingController.php:201
* @route '/bookings/{booking}'
*/
cancel.delete = (args: { booking: number | { id: number } } | [booking: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: cancel.url(args, options),
    method: 'delete',
})

const bookings = {
    index: Object.assign(index, index),
    store: Object.assign(store, store),
    checkIn: Object.assign(checkIn, checkIn),
    noShow: Object.assign(noShow, noShow),
    cancel: Object.assign(cancel, cancel),
}

export default bookings