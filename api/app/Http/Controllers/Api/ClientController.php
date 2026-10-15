<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Admin management of clients (customers). */
class ClientController extends Controller
{
    public function index(Request $r)
    {
        $q = Client::withCount(['phoneNumbers', 'templates'])->with('phoneNumbers:id,client_id,display_number,name,phone_number_id')->orderBy('name');
        if ($r->filled('q')) $q->where(fn ($w) => $w->where('name', 'like', "%{$r->q}%")->orWhere('company', 'like', "%{$r->q}%")->orWhere('email', 'like', "%{$r->q}%"));
        if ($r->filled('status')) $q->where('is_active', $r->status === 'active');
        return $q->get();
    }

    public function show(Client $client)
    {
        return $client->loadCount(['phoneNumbers', 'templates'])->load(['phoneNumbers', 'templates:id,client_id,name,language,status,category']);
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'name' => 'required|string|max:255', 'company' => 'nullable|string|max:255',
            'email' => 'required|email|max:255|unique:clients,email', 'password' => 'required|string|min:8|max:128',
            'is_active' => 'boolean',
        ]);
        return response()->json(Client::create($d + ['is_active' => true])->loadCount(['phoneNumbers', 'templates']), 201);
    }

    public function update(Request $r, Client $client)
    {
        $d = $r->validate([
            'name' => 'sometimes|required|string|max:255', 'company' => 'nullable|string|max:255',
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('clients', 'email')->ignore($client->id)],
            'is_active' => 'sometimes|boolean',
        ]);
        $client->update($d);
        if ($client->wasChanged('is_active') && ! $client->is_active) $client->tokens()->delete(); // deactivation logs them out
        return $client->loadCount(['phoneNumbers', 'templates']);
    }

    /** Set a given password, or generate one and return it once. Logs the client out everywhere. */
    public function resetPassword(Request $r, Client $client)
    {
        $d = $r->validate(['password' => 'nullable|string|min:8|max:128']);
        $plain = $d['password'] ?? Str::password(14, symbols: false);
        $client->update(['password' => $plain]);
        $client->tokens()->delete();
        return ['password' => $plain, 'generated' => empty($d['password'])];
    }

    public function destroy(Client $client)
    {
        $client->tokens()->delete();
        $client->delete(); // numbers/templates are kept and become unassigned
        return response()->noContent();
    }
}
