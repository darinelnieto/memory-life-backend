<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\MemoryController;
use App\Http\Controllers\MemoryLeafController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TreeMemberController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Autenticación
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

    // Google OAuth
    Route::prefix('google')->group(function () {
        Route::get('redirect', [SocialAuthController::class, 'redirectToGoogle']);
        Route::post('token', [SocialAuthController::class, 'handleGoogleToken']);
    });
});

// Rutas protegidas
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('families', FamilyController::class);

    // Perfil del usuario autenticado
    Route::get('profile', [ProfileController::class, 'show']);
    Route::patch('profile', [ProfileController::class, 'update']);
    Route::post('profile/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::post('profile/cover', [ProfileController::class, 'uploadCover']);

    // Feed (posts) y Memory Leaf — siempre con contexto de familia
    Route::prefix('families/{family}')->group(function () {
        // Posts del feed
        Route::get('posts', [PostController::class, 'index']);
        Route::post('posts', [PostController::class, 'store']);
        Route::delete('posts/{post}', [PostController::class, 'destroy']);

        // Memory Leaves
        Route::get('memory-leaves', [MemoryLeafController::class, 'index']);
        Route::post('memory-leaves', [MemoryLeafController::class, 'store']);
        Route::get('memory-leaves/{memoryLeaf}', [MemoryLeafController::class, 'show']);
        Route::patch('memory-leaves/{memoryLeaf}', [MemoryLeafController::class, 'update']);
        Route::post('memory-leaves/{memoryLeaf}/avatar', [MemoryLeafController::class, 'uploadAvatar']);
        Route::delete('memory-leaves/{memoryLeaf}', [MemoryLeafController::class, 'destroy']);

        // Memorias de un Memory Leaf
        Route::get('memory-leaves/{memoryLeaf}/memories', [MemoryController::class, 'index']);
        Route::post('memory-leaves/{memoryLeaf}/memories', [MemoryController::class, 'store']);
        Route::delete('memory-leaves/{memoryLeaf}/memories/{memory}', [MemoryController::class, 'destroy']);

        // Family Tree
        Route::get('tree', [TreeMemberController::class, 'tree']);
        Route::get('tree-members', [TreeMemberController::class, 'index']);
        Route::post('tree-members', [TreeMemberController::class, 'store']);
        Route::patch('tree-members/{member}', [TreeMemberController::class, 'update']);
        Route::delete('tree-members/{member}', [TreeMemberController::class, 'destroy']);
    });
});
