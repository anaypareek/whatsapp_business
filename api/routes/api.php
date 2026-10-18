<?php
use App\Http\Controllers\Api;
use App\Http\Middleware\ApiKeyAuth;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureClient;
use App\Http\Middleware\EnsureStaff;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [Api\AuthController::class, 'login'])->middleware('throttle:10,1');

Route::get('/webhook/whatsapp', [Api\WebhookController::class, 'verify']);
Route::post('/webhook/whatsapp', [Api\WebhookController::class, 'receive']);

// browser JS errors from both portals (public: errors can happen before/without login)
Route::post('/client-errors', [Api\ErrorLogController::class, 'report'])->middleware('throttle:20,1');

Route::post('/v1/messages/send', Api\ExternalSendController::class)->middleware([ApiKeyAuth::class, 'throttle:120,1']);

// ---- Client portal ----
Route::post('/client/auth/login', [Api\ClientPortalController::class, 'login'])->middleware('throttle:10,1');
Route::middleware(['auth:sanctum', EnsureClient::class])->prefix('client')->group(function () {
    Route::get('/auth/me', [Api\ClientPortalController::class, 'me']);
    Route::post('/auth/logout', [Api\ClientPortalController::class, 'logout']);
    Route::get('/dashboard', [Api\ClientPortalController::class, 'dashboard']);
    Route::get('/numbers', [Api\ClientPortalController::class, 'numbers']);
    Route::get('/templates', [Api\ClientPortalController::class, 'templates']);
    Route::get('/templates/{template}/test', [Api\TemplateController::class, 'testPreview']);
    Route::post('/templates/{template}/test', [Api\TemplateController::class, 'testSend'])->middleware('throttle:20,1');
    Route::get('/conversations', [Api\ClientPortalController::class, 'conversations']);
    Route::get('/messages/thread', [Api\ClientPortalController::class, 'thread']);
    Route::get('/messages/poll', [Api\ClientPortalController::class, 'poll']);
    Route::post('/messages/send', [Api\ClientPortalController::class, 'send']);
    Route::get('/number-requests', [Api\ClientPortalController::class, 'numberRequests']);
    Route::post('/number-requests', [Api\ClientPortalController::class, 'storeNumberRequest'])->middleware('throttle:20,1');
    Route::get('/template-requests', [Api\ClientPortalController::class, 'templateRequests']);
    Route::post('/template-requests', [Api\ClientPortalController::class, 'storeTemplateRequest'])->middleware('throttle:20,1');
    Route::get('/flow-numbers', [Api\FlowController::class, 'numberOptions']);
    Route::post('/flows-simulate', [Api\FlowController::class, 'simulate'])->middleware('throttle:120,1');
    Route::get('/flows', [Api\FlowController::class, 'index']);
    Route::post('/flows', [Api\FlowController::class, 'store']);
    Route::get('/flows/{flow}', [Api\FlowController::class, 'show'])->whereNumber('flow');
    Route::put('/flows/{flow}', [Api\FlowController::class, 'update'])->whereNumber('flow');
    Route::delete('/flows/{flow}', [Api\FlowController::class, 'destroy'])->whereNumber('flow');
    Route::post('/flows/{flow}/duplicate', [Api\FlowController::class, 'duplicate'])->whereNumber('flow');
    Route::get('/flows/{flow}/stats', [Api\FlowController::class, 'stats'])->whereNumber('flow');
    Route::get('/stats', [Api\ClientPortalController::class, 'stats']);
    Route::get('/stats/templates/{template}', [Api\ClientPortalController::class, 'templateSends'])->whereNumber('template');
});

// ---- Staff / admin portal (tokens must belong to a User) ----
Route::middleware(['auth:sanctum', EnsureStaff::class])->group(function () {
    Route::get('/dashboard', Api\DashboardController::class);
    Route::get('/auth/me', [Api\AuthController::class, 'me']);
    Route::post('/auth/logout', [Api\AuthController::class, 'logout']);

    Route::apiResource('phone-numbers', Api\PhoneNumberController::class);
    Route::post('/phone-numbers-sync', [Api\PhoneNumberController::class, 'sync']);
    Route::post('/phone-numbers/{phoneNumber}/register', [Api\PhoneNumberController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/phone-numbers/{phoneNumber}/refresh', [Api\PhoneNumberController::class, 'refresh']);

    Route::apiResource('templates', Api\TemplateController::class);
    Route::get('/templates/{template}/test', [Api\TemplateController::class, 'testPreview']);
    Route::post('/templates/{template}/test', [Api\TemplateController::class, 'testSend'])->middleware('throttle:20,1');
    Route::post('/templates-sync', [Api\TemplateController::class, 'sync']);
    Route::post('/messages/send-template', [Api\MessageController::class, 'sendTemplate']);
    Route::post('/messages/send', [Api\MessageController::class, 'send']);
    Route::get('/conversations', [Api\MessageController::class, 'conversations']);
    Route::get('/messages/thread', [Api\MessageController::class, 'thread']);
    Route::get('/messages/poll', [Api\MessageController::class, 'poll']);

    Route::get('/flow-numbers', [Api\FlowController::class, 'numberOptions']);
    Route::post('/flows-simulate', [Api\FlowController::class, 'simulate'])->middleware('throttle:120,1');
    Route::get('/flows', [Api\FlowController::class, 'index']);
    Route::post('/flows', [Api\FlowController::class, 'store']);
    Route::get('/flows/{flow}', [Api\FlowController::class, 'show'])->whereNumber('flow');
    Route::put('/flows/{flow}', [Api\FlowController::class, 'update'])->whereNumber('flow');
    Route::delete('/flows/{flow}', [Api\FlowController::class, 'destroy'])->whereNumber('flow');
    Route::post('/flows/{flow}/duplicate', [Api\FlowController::class, 'duplicate'])->whereNumber('flow');
    Route::get('/flows/{flow}/stats', [Api\FlowController::class, 'stats'])->whereNumber('flow');
    Route::apiResource('auto-replies', Api\AutoReplyRuleController::class)->except('show')->parameters(['auto-replies' => 'autoReply']);

    Route::put('/templates/{template}/client', [Api\TemplateController::class, 'assignClient']);
    Route::get('/clients-list', fn () => \App\Models\Client::orderBy('name')->get(['id', 'name', 'company', 'is_active']));

    Route::middleware(EnsureAdmin::class)->group(function () {
        Route::get('/error-logs/count', [Api\ErrorLogController::class, 'count']);
        Route::delete('/error-logs/clear', [Api\ErrorLogController::class, 'clear']);
        Route::get('/error-logs', [Api\ErrorLogController::class, 'index']);
        Route::get('/error-logs/{errorLog}', [Api\ErrorLogController::class, 'show']);
        Route::post('/error-logs/{errorLog}/resolve', [Api\ErrorLogController::class, 'resolve']);
        Route::apiResource('clients', Api\ClientController::class);
        Route::get('/requests/pending-counts', fn () => ['numbers' => \App\Models\PhoneNumberRequest::where('status', 'pending')->count(),
            'templates' => \App\Models\TemplateRequest::where('status', 'pending')->count()]);
        Route::get('/number-requests', [Api\NumberRequestController::class, 'index']);
        Route::post('/number-requests/{numberRequest}/approve', [Api\NumberRequestController::class, 'approve']);
        Route::post('/number-requests/{numberRequest}/reject', [Api\NumberRequestController::class, 'reject']);
        Route::post('/number-requests/{numberRequest}/register', [Api\NumberRequestController::class, 'retryRegistration']);
        Route::get('/template-requests', [Api\TemplateRequestController::class, 'index']);
        Route::get('/template-requests/{templateRequest}', [Api\TemplateRequestController::class, 'show']);
        Route::put('/template-requests/{templateRequest}', [Api\TemplateRequestController::class, 'update']);
        Route::post('/template-requests/{templateRequest}/approve', [Api\TemplateRequestController::class, 'approve']);
        Route::post('/template-requests/{templateRequest}/reject', [Api\TemplateRequestController::class, 'reject']);
        Route::post('/clients/{client}/reset-password', [Api\ClientController::class, 'resetPassword']);
        Route::apiResource('users', Api\UserController::class)->only(['index', 'store', 'destroy']);
        Route::get('/settings', [Api\SettingsController::class, 'show']);
        Route::put('/settings', [Api\SettingsController::class, 'update']);
        Route::apiResource('api-keys', Api\ApiKeyController::class)->only(['index', 'store', 'destroy'])->parameters(['api-keys' => 'apiKey']);
    });
});
