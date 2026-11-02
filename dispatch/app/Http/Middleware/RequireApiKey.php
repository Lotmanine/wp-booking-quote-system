<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiKey
{
    /**
     * Fails closed: with no key configured, nobody gets in.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('dispatch.api_key');
        $given = (string) $request->header('X-Api-Key', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
