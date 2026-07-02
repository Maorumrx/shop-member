import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::index
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:91
* @route '/member/dev-login'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/member/dev-login',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::index
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:91
* @route '/member/dev-login'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::index
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:91
* @route '/member/dev-login'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::index
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:91
* @route '/member/dev-login'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::login
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:125
* @route '/member/dev-login/{member}'
*/
export const login = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: login.url(args, options),
    method: 'get',
})

login.definition = {
    methods: ["get","head"],
    url: '/member/dev-login/{member}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::login
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:125
* @route '/member/dev-login/{member}'
*/
login.url = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return login.definition.url
            .replace('{member}', parsedArgs.member.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::login
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:125
* @route '/member/dev-login/{member}'
*/
login.get = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: login.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::login
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:125
* @route '/member/dev-login/{member}'
*/
login.head = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: login.url(args, options),
    method: 'head',
})

const MemberDevLoginController = { index, login }

export default MemberDevLoginController