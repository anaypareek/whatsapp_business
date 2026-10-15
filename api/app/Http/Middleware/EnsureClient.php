<?php
namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;

class EnsureClient
{
    public function handle(Request $request, Closure $next)
    {
        $c = $request->user();
        abort_unless($c instanceof Client, 403, 'Clients only');
        if (! $c->is_active) { $c->currentAccessToken()?->delete(); abort(403, 'Your account is inactive. Contact the administrator.'); }
        return $next($request);
    }
}
