<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer API-key auth for the ingest API. Keys are Sanctum personal access
 * tokens with the "ingest" ability, created from the dashboard settings page.
 */
class ApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getMethod() === 'OPTIONS') {
            return response()->noContent(204);
        }

        $bearer = $request->bearerToken();

        if (! $bearer) {
            return $this->unauthorized('Missing API key.');
        }

        $token = PersonalAccessToken::findToken($bearer);

        if (! $token) {
            return $this->unauthorized('Invalid API key.');
        }

        if (! $token->can('ingest')) {
            return response()->json([
                'success' => false,
                'message' => 'This API key does not have ingest access.',
            ], 403);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->setUserResolver(fn () => $token->tokenable);

        return $next($request);
    }

    protected function unauthorized(string $message): Response
    {
        return response()->json(['success' => false, 'message' => $message], 401);
    }
}
