<?php

namespace Elliptic\Backfill\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BackfillAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('backfill.auth_token');

        if (empty($token)) {
            return response()->json([
                'error' => 'Backfill auth token is not configured on this server.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $provided = $request->bearerToken();

        if (! $provided || ! hash_equals($token, $provided)) {
            return response()->json([
                'error' => 'Unauthorized. Invalid or missing sync token.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
