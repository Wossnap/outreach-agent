<?php

use App\Http\Middleware\ApiAuth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('outreach:dispatch-due-emails')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->onFailure(fn () => Log::error('outreach:dispatch-due-emails scheduled run failed'));

        $schedule->command('gmail:poll-mailboxes')
            ->everyTwoMinutes()
            ->withoutOverlapping(10)
            ->onFailure(fn () => Log::error('gmail:poll-mailboxes scheduled run failed'));

        $schedule->command('outreach:advance-sequences')
            ->everyTenMinutes()
            ->withoutOverlapping(10)
            ->onFailure(fn () => Log::error('outreach:advance-sequences scheduled run failed'));

        $schedule->command('outreach:reconcile-stuck')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onFailure(fn () => Log::error('outreach:reconcile-stuck scheduled run failed'));

        $schedule->command('mailboxes:refresh-tokens')
            ->daily()
            ->withoutOverlapping(30)
            ->onFailure(fn () => Log::error('mailboxes:refresh-tokens scheduled run failed'));
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.auth' => ApiAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 422);
            }
        });
    })->create();
