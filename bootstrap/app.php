<?php

use App\Http\Middleware\ResolveRequestCorrelation;
use App\Support\GatewayException;
use App\Support\RequestCorrelation;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Laravel's default guest redirect calls route('login'), which does
        // not exist here and used to turn unauthenticated API requests
        // without Accept: application/json into a 500 HTML page.
        $middleware->redirectGuestsTo(function (Request $request): ?string {
            if ($request->is('api/*')) {
                return null;
            }

            return '/login';
        });

        // Authenticate sits on the framework priority list, which would
        // otherwise reorder auth:sanctum ahead of ResolveRequestCorrelation
        // despite the route group order. Keep correlation first on /tenant/*.
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            ResolveRequestCorrelation::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $e) {
            return $request->is('api/*');
        });

        // Domain errors already carry their own HTTP contract; do not
        // duplicate them into the log (timeout/offline are audited separately).
        $exceptions->dontReport([
            GatewayException::class,
        ]);

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return (new GatewayException(
                'UNAUTHENTICATED',
                'Authentication required.',
                401,
                null,
                RequestCorrelation::currentId(),
            ))->toResponse();
        });

        $exceptions->render(function (GatewayException $e) {
            return $e->toResponse();
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException || $e instanceof HttpExceptionInterface) {
                return null;
            }

            return (new GatewayException(
                'INTERNAL_ERROR',
                'Internal server error.',
                500,
                null,
                RequestCorrelation::currentId(),
            ))->toResponse();
        });
    })->create();
