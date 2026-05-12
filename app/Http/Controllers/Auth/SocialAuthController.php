<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * Redirige al usuario a la pantalla de OAuth de Google.
     * Usado solo si el frontend NO maneja el flujo OAuth directamente.
     */
    public function redirectToGoogle(): JsonResponse
    {
        $url = Socialite::driver('google')->stateless()->redirect()->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    /**
     * Recibe el token de Google desde el frontend (flujo mobile/SPA).
     * El frontend envía el token obtenido con Google Sign-In SDK.
     */
    public function handleGoogleToken(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate(['token' => 'required|string']);

        $googleUser = Socialite::driver('google')->stateless()->userFromToken($request->token);

        $user = User::updateOrCreate(
            ['google_id' => $googleUser->getId()],
            [
                'name'              => $googleUser->getName(),
                'email'             => $googleUser->getEmail(),
                'avatar'            => $googleUser->getAvatar(),
                'email_verified_at' => now(),
            ]
        );

        if (!$user->hasAnyRole(['free', 'medium', 'premium', 'super_admin'])) {
            $user->assignRole('free');
        }

        $apiToken = $user->createToken('google-auth')->plainTextToken;

        return response()->json([
            'token' => $apiToken,
            'user'  => [
                'id'     => $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'avatar' => $user->avatar,
                'roles'  => $user->getRoleNames(),
            ],
        ]);
    }
}
