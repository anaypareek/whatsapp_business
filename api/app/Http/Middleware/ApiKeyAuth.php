<?php
namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;

class ApiKeyAuth
{
    public function handle(Request $request, Closure $next)
    {
        $plain = $request->bearerToken() ?? $request->header('X-API-Key');
        $key = $plain ? ApiKey::findByPlain($plain) : null;
        if (! $key) return response()->json(['message' => 'Invalid or missing API key'], 401);
        $key->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('api_key', $key);
        return $next($request);
    }
}
