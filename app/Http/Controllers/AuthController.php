<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Deliberately minimal "join with a display name" sign-in, so the demo can
 * be opened in two browsers without registering accounts. Each join creates
 * a fresh user; swap this for Fortify or a starter kit in a real deployment.
 * Everything downstream (session guard, presence auth) is the standard
 * Laravel machinery and would not change.
 */
class AuthController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()?->toPresence()]);
    }

    public function join(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:40']]);

        $user = User::create([
            'name' => trim($validated['name']),
            'email' => 'guest-'.Str::uuid().'@guests.invalid',
            'password' => Str::random(40),
            'color' => User::COLORS[array_rand(User::COLORS)],
        ]);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return response()->json(['user' => $user->toPresence()], 201);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
