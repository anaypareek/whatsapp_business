<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    public function index() { return ApiKey::orderByDesc('id')->get(); }
    public function store(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:100']);
        [$key, $plain] = ApiKey::generate($d['name'], $r->user()->id);
        return response()->json(['api_key' => $key, 'plain_key' => $plain, 'note' => 'Copy now; it will not be shown again.'], 201);
    }
    public function destroy(ApiKey $apiKey) { $apiKey->delete(); return response()->noContent(); }
}
