<?php

use App\Http\Middleware\AuthenticateBot;
use App\Http\Middleware\AuthenticateWorker;
use App\Http\Middleware\BindWorkerAccount;
use App\Services\Monitoring\ErrorReporter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Machine endpoints for the trading executor. Stateless: no session, no CSRF.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Bearer-token auth for the MQL5 EA and any future executor.
        $middleware->alias([
            'bot.auth' => AuthenticateBot::class,
            'worker.auth' => AuthenticateWorker::class,
            'worker.account' => BindWorkerAccount::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This application watched the trading bot closely and itself not at all: a 500 on
        // a customer's page was invisible until they emailed, and the only way to find one
        // was reading laravel.log over SSH.
        //
        // `report` rather than `reportable`, so Laravel's own logging still happens - this
        // adds an incident on /logs and a message to the operator, it does not replace the
        // stack trace in the file.
        $exceptions->report(function (Throwable $e): void {
            app(ErrorReporter::class)->report($e);
        });
    })->create();
