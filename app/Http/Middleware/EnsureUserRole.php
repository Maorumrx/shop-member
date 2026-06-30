<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-guard authorization gate (architecture.md §3.2).
 *
 * Usage on a route: `->middleware('role:owner')` or `'role:owner,staff'`.
 * Operates on the default (`web`/`users`) admin guard — it reads the
 * authenticated User's `role` enum and 403s when it is not in the allow-list.
 * It does NOT authenticate; pair it with `auth` so an unauthenticated request
 * is redirected to login first.
 */
final class EnsureUserRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     * @param  string  ...$roles  One or more allowed role values (e.g. "owner").
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        // `role` is cast to UserRole on the User model; compare by value so the
        // string args from the route definition line up with the enum.
        $role = $user->role instanceof UserRole ? $user->role->value : (string) $user->role;

        if (! in_array($role, $roles, true)) {
            abort(403);
        }

        return $next($request);
    }
}
