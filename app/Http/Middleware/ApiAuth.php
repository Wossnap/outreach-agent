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
 *
 * Routes list the abilities that satisfy them and the key needs any one:
 * `api.auth:read,write`.
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

        $token = $this->findToken($bearer);

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

        // Stashed for the rate limiter, which has to count per key rather than
        // per address: several systems pushing from one server must not
        // throttle each other.
        $request->attributes->set('api_token', $token);

        return $next($request);
    }

    /**
     * Look up a key, treating a malformed one as simply not found.
     *
     * A key reads "<id>|<secret>", and Sanctum looks the id up directly. When
     * the id is not a number, Postgres rejects the query outright rather than
     * returning no rows, so a mistyped key came back as a 500 with the
     * database host in the log instead of a 401. Pasting over the middle of
     * the documentation page's {YOUR_AUTH_KEY} placeholder does exactly that,
     * leaving the braces attached.
     */
    protected function findToken(string $bearer): ?PersonalAccessToken
    {
        if (str_contains($bearer, '|')) {
            [$id] = explode('|', $bearer, 2);

            if (! ctype_digit($id)) {
                return null;
            }
        }

        return PersonalAccessToken::findToken($bearer);
    }

    protected function unauthorized(string $message): Response
    {
        return response()->json(['success' => false, 'message' => $message], 401);
    }
}
