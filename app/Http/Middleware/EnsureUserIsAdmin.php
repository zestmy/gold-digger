<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Operator-only pages inside the dashboard.
 *
 * The strategy editor and the improver tune what generates every subscriber's signals.
 * They were in the customer menu because the dashboard began as one person's bot; as a
 * product they are the operator's, and a subscriber reaching one by address gets the same
 * answer the Filament panel gives - 403, not a redirect that pretends the page is missing.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_admin, 403);

        return $next($request);
    }
}
