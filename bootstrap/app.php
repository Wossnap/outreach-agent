<?php

use App\Http\Middleware\ApiAuth;
use App\Http\Middleware\EnsureApiActionIsEnabled;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

        $schedule->command('health:evaluate')
            ->hourly()
            ->withoutOverlapping(30)
            ->onFailure(fn () => Log::error('health:evaluate scheduled run failed'));

        $schedule->command('health:check-dns')
            ->dailyAt('06:00')
            ->withoutOverlapping(30)
            ->onFailure(fn () => Log::error('health:check-dns scheduled run failed'));

        $schedule->command('health:check-dns --dnsbl')
            ->dailyAt('06:30')
            ->withoutOverlapping(30)
            ->onFailure(fn () => Log::error('health:check-dns --dnsbl scheduled run failed'));
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.auth' => ApiAuth::class,
            'api.enabled' => EnsureApiActionIsEnabled::class,
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

        // Everything under /api answers in the same {success, message} shape,
        // so a client can read one field to tell success from failure instead
        // of parsing Laravel's default HTML or bare-message JSON.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: match ($e->getStatusCode()) {
                    404 => 'Not found.',
                    405 => 'Method not allowed.',
                    429 => 'Too many requests.',
                    default => 'Request failed.',
                },
            ], $e->getStatusCode());
        });
    })->create();
