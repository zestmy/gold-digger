<?php

namespace App\Http\Middleware;

use Filament\Http\Middleware\Authenticate;

/**
 * Authenticate Admin
 *
 * Filament's own guard, with one change: somebody who is not signed in is sent to the
 * dashboard's login rather than to a login page of the panel's own.
 *
 * ## Why the panel has no login page
 *
 * It used to. Filament's stock page calls `Auth::attempt()` and stops there, so an
 * administrator with two-factor switched on could sign in at `/admin/login` with a password
 * alone - the second factor was enforced by `LoginForm` on `/login`, and only there. A
 * second door with a weaker lock is the door that gets used.
 *
 * Both routes authenticate against the same `web` guard, so a session started at `/login`
 * is exactly what the panel wants. Removing `->login()` from the panel leaves nothing
 * behind for Filament to redirect to, which is why this class exists: `redirectTo()` is
 * the only thing the parent leaves open.
 *
 * Everything else - the `is_admin` check via `canAccessPanel()`, the 403 for an ordinary
 * account - is inherited unchanged.
 */
class AuthenticateAdmin extends Authenticate
{
    protected function redirectTo($request): ?string
    {
        return route('login');
    }
}
