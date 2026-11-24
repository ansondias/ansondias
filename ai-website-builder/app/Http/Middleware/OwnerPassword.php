<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * There are no user accounts (see DECISIONS.md). If BUILDER_PASSWORD is set, the builder
 * (everything except published sites) sits behind HTTP basic auth so a deployed copy is not open.
 */
class OwnerPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $password = config('app.builder_password');
        if (! $password || hash_equals((string) $password, (string) $request->getPassword())) {
            return $next($request);
        }

        return response('Password required', 401, ['WWW-Authenticate' => 'Basic realm="AI Website Builder"']);
    }
}
