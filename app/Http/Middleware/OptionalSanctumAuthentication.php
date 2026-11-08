<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class OptionalSanctumAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->setUserResolver(fn ($guard = null) => Auth::guard($guard ?? 'sanctum')->user());

        return $next($request);
    }
}
