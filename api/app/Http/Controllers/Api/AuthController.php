<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required', 'device_name' => 'nullable|string|max:60']);
        $u = User::where('email', $d['email'])->first();
        if (! $u || ! Hash::check($d['password'], $u->password)) throw ValidationException::withMessages(['email' => 'Invalid credentials']);
        $device = trim((string) ($d['device_name'] ?? ''));
        // Master app sends device_name so staff PATs are distinguishable from the web portal ("portal").
        $name = $device !== '' ? 'mobile:'.mb_substr($device, 0, 60) : 'portal';
        return ['token' => $u->createToken($name)->plainTextToken, 'user' => $u];
    }
    public function me(Request $r) { return $r->user(); }
    public function logout(Request $r) { $r->user()->currentAccessToken()?->delete(); return ['ok' => true]; }
}
