<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // The Dimsum Weigu installation is Indonesian-only, regardless of
        // browser headers, stale cookies, or a user's previous preference.
        app()->setLocale('id');

        return $next($request);
    }
}
