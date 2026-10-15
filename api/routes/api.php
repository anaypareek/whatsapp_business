<?php
use App\Http\Controllers\Api;
use App\Http\Middleware\ApiKeyAuth;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [Api\AuthController::class, 'login'])->middleware('throttle:10,1');

Route::get('/webhook/whatsapp', [Api\WebhookController::class, 'verify']);
Route::post('/webhook/whatsapp', [Api\WebhookController::class, 'receive']);

Route::post('/v1/messages/send', Api\ExternalSendController::class)->middleware([ApiKeyAuth::class, 'throttle:120,1']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard', Api\DashboardController::class);
    Route::get('/auth/me', [Api\AuthController::class, 'me']);
    Route::post('/auth/logout', [Api\AuthController::class, 'logout']);

    Route::apiResource('phone-numbers', Api\PhoneNumberController::class);
    Route::post('/phone-numbers-sync', [Api\PhoneNumberController::class, 'sync']);
    Route::post('/phone-numbers/{phoneNumber}/register', [Api\PhoneNumberController::class, 'register']);

    Route::apiResource('templates', Api\TemplateController::class);
    Route::post('/templates-sync', [Api\TemplateController::class, 'sync']);
    Route::post('/messages/send-template', [Api\MessageController::class, 'sendTemplate']);
    Route::post('/messages/send', [Api\MessageController::class, 'send']);
    Route::get('/conversations', [Api\MessageController::class, 'conversations']);
    Route::get('/messages/thread', [Api\MessageController::class, 'thread']);
    Route::get('/messages/poll', [Api\MessageController::class, 'poll']);

    Route::apiResource('auto-replies', Api\AutoReplyRuleController::class)->except('show')->parameters(['auto-replies' => 'autoReply']);

    Route::middleware(EnsureAdmin::class)->group(function () {
        Route::apiResource('users', Api\UserController::class)->only(['index', 'store', 'destroy']);
        Route::get('/settings', [Api\SettingsController::class, 'show']);
        Route::put('/settings', [Api\SettingsController::class, 'update']);
        Route::apiResource('api-keys', Api\ApiKeyController::class)->only(['index', 'store', 'destroy'])->parameters(['api-keys' => 'apiKey']);
    });
});
