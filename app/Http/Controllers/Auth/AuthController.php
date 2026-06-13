<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Spatie\Permission\Models\Role;
use Throwable;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'identifier' => 'required|string',
            'password'   => 'required|string',
        ]);

        $identifier = trim((string) $request->identifier);

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower($identifier)])
            ->orWhere('phone', $identifier)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciales incorrectas'], 401);
        }

        $token = $user->createToken('auth')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'     => $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'avatar' => $user->avatar,
                'roles'  => $user->getRoleNames(),
            ],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name'                  => 'required|string|max:255',
            'username'              => 'nullable|string|max:50|unique:users|alpha_dash',
            'email'                 => 'required|email|unique:users',
            'phone'                 => 'required|string|max:30|unique:users,phone',
            'password'              => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $username = trim((string) $request->input('username', ''));
        $email = strtolower(trim((string) $request->email));
        $phone = trim((string) $request->phone);

        $user = User::create([
            'name'     => $request->name,
            'username' => $username !== '' ? $username : null,
            'email'    => $email,
            'phone'    => $phone,
            'password' => Hash::make($request->password),
        ]);

        try {
            // Ensure default role exists in environments where seeders were not run.
            Role::findOrCreate('free');
            $user->assignRole('free');
        } catch (Throwable $exception) {
            // Do not fail registration if role metadata is misconfigured in production.
            Log::warning('User registered without role assignment', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $token = $user->createToken('auth')->plainTextToken;

        return response()->json([
            'message' => 'Cuenta creada correctamente.',
            'token' => $token,
            'user'  => [
                'id'     => $user->id,
                'name'   => $user->name,
                'username' => $user->username,
                'email'  => $user->email,
                'avatar' => $user->avatar,
                'roles'  => $user->getRoleNames(),
            ],
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        Password::sendResetLink($request->only('email'));

        // Always return a generic success response to avoid user enumeration.
        return response()->json([
            'message' => 'Si el correo existe, recibirás un enlace para restablecer tu contraseña.',
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'message' => 'Contraseña restablecida correctamente.',
        ]);
    }
}
