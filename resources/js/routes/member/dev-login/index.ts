import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::index
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:33
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
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:33
* @route '/member/dev-login'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::index
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:33
* @route '/member/dev-login'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::index
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:33
* @route '/member/dev-login'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::index
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:33
* @route '/member/dev-login'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::index
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:33
* @route '/member/dev-login'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Dev\MemberDevLoginController::index
* @see app/Http/Controllers/Dev/MemberDevLoginController.php:33
* @route '/member/dev-login'
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
