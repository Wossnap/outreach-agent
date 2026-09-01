<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer API-key auth. Keys are Sanctum personal access tokens created from
 * the dashboard settings page, each carrying one or more abilities.
 *
 * Abilities:
 *   read     - GET anything
 *   write    - create and change data, including pushing contacts
 *   approve  - approve a draft so it sends (also gated by config, see below)
 *   ingest   - legacy, kept so keys issued before abilities existed still
 *              reach POST /api/contacts
 *
 * Routes list the abilities that satisfy them and the key needs any one:
 * `api.auth:write,ingest`.
 */
class ApiAuth
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
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

        $abilities = $abilities ?: ['read'];

        if (! collect($abilities)->contains(fn (string $ability) => $token->can($ability))) {
            return response()->json([
                'success' => false,
                'message' => 'This API key does not have '.implode(' or ', $abilities).' access.',
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
