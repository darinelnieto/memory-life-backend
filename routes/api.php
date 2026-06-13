<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\MemberAccountRegistrationController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatGroupController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\FamilyInvitationController;
use App\Http\Controllers\GlobalNotificationController;
use App\Http\Controllers\JourneyController;
use App\Http\Controllers\JourneyItemController;
use App\Http\Controllers\MemoryController;
use App\Http\Controllers\MemoryLeafController;
use App\Http\Controllers\ProfileCopyController;
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
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::get('member-register/{token}', [MemberAccountRegistrationController::class, 'show']);
    Route::post('member-register/{token}', [MemberAccountRegistrationController::class, 'register']);
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
    Route::post('families/{family}/cover', [FamilyController::class, 'uploadCover']);
    Route::post('families/{family}/members', [FamilyController::class, 'addMember']);

    // Invitaciones familiares
    Route::get('invitations/pending', [FamilyInvitationController::class, 'myInvitations']);
    Route::post('invitations/{token}/accept', [FamilyInvitationController::class, 'accept']);
    Route::post('invitations/{token}/reject', [FamilyInvitationController::class, 'reject']);
    Route::prefix('families/{family}/invitations')->group(function () {
        Route::get('', [FamilyInvitationController::class, 'index']);
        Route::post('', [FamilyInvitationController::class, 'invite']);
        Route::delete('{invitation}', [FamilyInvitationController::class, 'cancel']);
    });

    // Perfil del usuario autenticado
    Route::get('profile', [ProfileController::class, 'show']);
    Route::patch('profile', [ProfileController::class, 'update']);
    Route::post('profile/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::post('profile/cover', [ProfileController::class, 'uploadCover']);

    // Feed (posts) y Memory Leaf — siempre con contexto de familia
    Route::prefix('families/{family}')->group(function () {
        // Media del perfil del usuario autenticado en la familia seleccionada
        Route::get('profile/media', [ProfileController::class, 'media']);
        Route::get('profiles/{user}', [ProfileController::class, 'showByUser']);
        Route::get('profiles/{user}/media', [ProfileController::class, 'mediaByUser']);
        Route::get('copy-profile-preview', [ProfileCopyController::class, 'preview']);
        Route::post('copy-profile', [ProfileCopyController::class, 'copy']);

        // Chat directo entre miembros de la familia
        Route::get('chat/contacts', [ChatController::class, 'contacts']);
        Route::get('chat/conversations/{member}', [ChatController::class, 'conversation']);
        Route::post('chat/conversations/{member}', [ChatController::class, 'store']);
        Route::post('chat/conversations/{member}/messages/{chatMessage}/view', [ChatController::class, 'markViewed']);
        Route::patch('chat/conversations/{member}/messages/{chatMessage}', [ChatController::class, 'update']);
        Route::delete('chat/conversations/{member}/messages/{chatMessage}', [ChatController::class, 'destroy']);
        Route::post('chat/conversations/{member}/typing', [ChatController::class, 'typing']);
        Route::get('chat/groups', [ChatGroupController::class, 'index']);
        Route::post('chat/groups', [ChatGroupController::class, 'store']);
        Route::patch('chat/groups/{group}', [ChatGroupController::class, 'update']);
        Route::delete('chat/groups/{group}', [ChatGroupController::class, 'destroy']);
        Route::post('chat/groups/{group}/leave', [ChatGroupController::class, 'leave']);
        Route::get('chat/groups/{group}/messages', [ChatGroupController::class, 'messages']);
        Route::post('chat/groups/{group}/messages', [ChatGroupController::class, 'send']);
        Route::post('chat/groups/{group}/messages/{message}/view', [ChatGroupController::class, 'markViewed']);
        Route::patch('chat/groups/{group}/messages/{message}', [ChatGroupController::class, 'updateMessage']);
        Route::delete('chat/groups/{group}/messages/{message}', [ChatGroupController::class, 'destroyMessage']);
        Route::post('chat/groups/{group}/typing', [ChatGroupController::class, 'typing']);
        Route::get('notifications', [GlobalNotificationController::class, 'index']);

        // Posts del feed
        Route::get('posts', [PostController::class, 'index']);
        Route::post('posts', [PostController::class, 'store']);
        Route::patch('posts/{post}', [PostController::class, 'update']);
        Route::delete('posts/{post}', [PostController::class, 'destroy']);
        Route::get('posts/{post}/media', [PostController::class, 'downloadMedia']);
        Route::post('posts/{post}/like', [PostController::class, 'toggleLike']);
        Route::get('posts/{post}/likes', [PostController::class, 'likes']);
        Route::get('posts/{post}/comments', [PostController::class, 'comments']);
        Route::post('posts/{post}/comments', [PostController::class, 'addComment']);
        Route::patch('posts/{post}/comments/{comment}', [PostController::class, 'updateComment']);
        Route::delete('posts/{post}/comments/{comment}', [PostController::class, 'deleteComment']);
        Route::post('posts/{post}/repost', [PostController::class, 'toggleRepost']);
        Route::get('posts/{post}/reposts', [PostController::class, 'reposts']);

        // Memory Leaves
        Route::get('memory-leaves', [MemoryLeafController::class, 'index']);
        Route::post('memory-leaves', [MemoryLeafController::class, 'store']);
        Route::get('memory-leaves/share-users', [MemoryLeafController::class, 'shareUsers']);
        Route::get('memory-leaves/shares/pending', [MemoryLeafController::class, 'pendingShares']);
        Route::post('memory-leaves/shares/{share}/accept', [MemoryLeafController::class, 'acceptShare']);
        Route::post('memory-leaves/shares/{share}/reject', [MemoryLeafController::class, 'rejectShare']);
        Route::get('memory-leaves/{memoryLeaf}', [MemoryLeafController::class, 'show']);
        Route::patch('memory-leaves/{memoryLeaf}', [MemoryLeafController::class, 'update']);
        Route::post('memory-leaves/{memoryLeaf}/avatar', [MemoryLeafController::class, 'uploadAvatar']);
        Route::post('memory-leaves/{memoryLeaf}/share', [MemoryLeafController::class, 'share']);
        Route::delete('memory-leaves/{memoryLeaf}', [MemoryLeafController::class, 'destroy']);

        // Memorias de un Memory Leaf
        Route::get('memory-leaves/{memoryLeaf}/memories', [MemoryController::class, 'index']);
        Route::post('memory-leaves/{memoryLeaf}/memories', [MemoryController::class, 'store']);
        Route::delete('memory-leaves/{memoryLeaf}/memories/{memory}', [MemoryController::class, 'destroy']);

        // Family Tree
        Route::get('tree', [TreeMemberController::class, 'tree']);
        Route::get('tree-members', [TreeMemberController::class, 'index']);
        Route::get('tree-members/pets', [TreeMemberController::class, 'pets']);
        Route::get('tree-member-requests/outgoing', [TreeMemberController::class, 'outgoingRequests']);
        Route::post('tree-member-requests/{member}/cancel', [TreeMemberController::class, 'cancelOutgoingRequest']);
        Route::post('tree-members/{member}/account-invitation', [TreeMemberController::class, 'sendAccountInvitation']);
        Route::post('tree-members', [TreeMemberController::class, 'store']);
        Route::patch('tree-members/{member}', [TreeMemberController::class, 'update']);
        Route::delete('tree-members/{member}', [TreeMemberController::class, 'destroy']);
        Route::get('tree-members/{member}/profile', [TreeMemberController::class, 'profile']);
        Route::post('tree-members/{member}/claim', [TreeMemberController::class, 'claimAsMe']);
        Route::delete('tree-members/{member}/claim', [TreeMemberController::class, 'unclaimMe']);

        // Journeys
        Route::get('journeys', [JourneyController::class, 'index']);
        Route::post('journeys', [JourneyController::class, 'store']);
        Route::patch('journeys/{journey}', [JourneyController::class, 'update']);
        Route::get('journeys/{journey}', [JourneyController::class, 'show']);
        Route::delete('journeys/{journey}', [JourneyController::class, 'destroy']);
        Route::post('journeys/{journey}/items', [JourneyItemController::class, 'store']);
        Route::delete('journeys/{journey}/items/{item}', [JourneyItemController::class, 'destroy']);
    });
});
