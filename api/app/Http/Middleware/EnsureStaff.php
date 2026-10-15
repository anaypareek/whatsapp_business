<?php
namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/** Staff (admin portal) routes: the Sanctum token must belong to a User, never a Client. */
class EnsureStaff
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user() instanceof User, 403, 'Staff only');
        return $next($request);
    }
}
