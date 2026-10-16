<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [\App\Http\Middleware\ConsistentErrors::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // store reportable exceptions in error_logs (the file log still gets them too)
        $exceptions->report(function (Throwable $e) { \App\Services\ErrorLogger::exception($e); });
        // API errors: {message, error_code, log_id}; hide internals of 500s unless APP_DEBUG
        $exceptions->respond(function ($response, Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson()) || ! $response instanceof \Illuminate\Http\JsonResponse) return $response;
            $d = $response->getData(true);
            if (! is_array($d)) return $response;
            $s = $response->getStatusCode();
            $d['error_code'] ??= $e instanceof \App\Services\MetaApiException ? $e->errorCode() : (\App\Http\Middleware\ConsistentErrors::CODES[$s] ?? ($s >= 500 ? 'SERVER_ERROR' : 'HTTP_'.$s));
            $d['log_id'] ??= \App\Services\ErrorLogger::alreadyLogged($e);
            if ($s >= 500 && ! config('app.debug'))$d['message'] = 'Something went wrong on the server.';
            return $response->setData($d);
        });
    })->create();
