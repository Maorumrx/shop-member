import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Admin\MemberWalletController::adjust
* @see app/Http/Controllers/Admin/MemberWalletController.php:114
* @route '/members/{member}/wallet/adjust'
*/
export const adjust = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: adjust.url(args, options),
    method: 'post',
})

adjust.definition = {
    methods: ["post"],
    url: '/members/{member}/wallet/adjust',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Admin\MemberWalletController::adjust
* @see app/Http/Controllers/Admin/MemberWalletController.php:114
* @route '/members/{member}/wallet/adjust'
*/
adjust.url = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return adjust.definition.url
            .replace('{member}', parsedArgs.member.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\MemberWalletController::adjust
* @see app/Http/Controllers/Admin/MemberWalletController.php:114
* @route '/members/{member}/wallet/adjust'
*/
adjust.post = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: adjust.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\MemberWalletController::charge
* @see app/Http/Controllers/Admin/MemberWalletController.php:41
* @route '/members/{member}/wallet/charge'
*/
export const charge = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: charge.url(args, options),
    method: 'post',
})

charge.definition = {
    methods: ["post"],
    url: '/members/{member}/wallet/charge',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Admin\MemberWalletController::charge
* @see app/Http/Controllers/Admin/MemberWalletController.php:41
* @route '/members/{member}/wallet/charge'
*/
charge.url = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return charge.definition.url
            .replace('{member}', parsedArgs.member.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\MemberWalletController::charge
* @see app/Http/Controllers/Admin/MemberWalletController.php:41
* @route '/members/{member}/wallet/charge'
*/
charge.post = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: charge.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Admin\MemberWalletController::refund
* @see app/Http/Controllers/Admin/MemberWalletController.php:85
* @route '/members/{member}/wallet/refund'
*/
export const refund = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refund.url(args, options),
    method: 'post',
})

refund.definition = {
    methods: ["post"],
    url: '/members/{member}/wallet/refund',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Admin\MemberWalletController::refund
* @see app/Http/Controllers/Admin/MemberWalletController.php:85
* @route '/members/{member}/wallet/refund'
*/
refund.url = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return refund.definition.url
            .replace('{member}', parsedArgs.member.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Admin\MemberWalletController::refund
* @see app/Http/Controllers/Admin/MemberWalletController.php:85
* @route '/members/{member}/wallet/refund'
*/
refund.post = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refund.url(args, options),
    method: 'post',
})

const MemberWalletController = { adjust, charge, refund }

export default MemberWalletController