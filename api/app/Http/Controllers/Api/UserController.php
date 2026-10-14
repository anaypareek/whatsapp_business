<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index() { return User::orderBy('id')->get(); }
    public function store(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'email' => 'required|email|unique:users', 'password' => 'required|string|min:8', 'is_admin' => 'boolean']);
        return response()->json(User::create($d), 201);
    }
    public function destroy(Request $r, User $user)
    {
        abort_if($user->id === $r->user()->id, 422, 'You cannot delete yourself');
        $user->tokens()->delete();
        $user->delete();
        return response()->noContent();
    }
}
