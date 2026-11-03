<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        $allowed = $user !== null && collect($permissions)->contains(
            fn (string $permission) => $user->hasPermission($permission),
        );

        if (! $allowed) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $next($request);
    }
}
