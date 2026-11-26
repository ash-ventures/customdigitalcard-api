<?php

use Illuminate\Support\Str;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Http\Middleware\SecurityHeaders;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->alias(['admin' => \App\Http\Middleware\EnsureAdmin::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every API failure answers with a readable message, a field map and a support code so the
        // customer is never shown a vague "request failed" text.
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            if ($exception instanceof ValidationException) {
                $errors = $exception->errors();
                $first = collect($errors)->flatten()->first(fn ($message) => is_string($message) && $message !== '');

                return response()->json([
                    'message' => is_string($first) ? $first : 'Sila semak maklumat yang diisi.',
                    'errors' => $errors,
                    'error_code' => 'validation_failed',
                ], $exception->status);
            }

            if ($exception instanceof AuthenticationException) {
                return response()->json([
                    'message' => 'Sesi anda telah tamat. Sila log masuk semula untuk meneruskan.',
                    'error_code' => 'unauthenticated',
                ], 401);
            }

            if ($exception instanceof ThrottleRequestsException) {
                return response()->json([
                    'message' => 'Terlalu banyak cubaan dalam masa singkat. Sila tunggu sebentar sebelum cuba lagi.',
                    'error_code' => 'too_many_attempts',
                ], 429);
            }

            if ($exception instanceof AuthorizationException) {
                return response()->json([
                    'message' => 'Anda tiada kebenaran untuk tindakan ini.',
                    'error_code' => 'forbidden',
                ], 403);
            }

            if ($exception instanceof ModelNotFoundException || $exception instanceof RecordNotFoundException) {
                return response()->json([
                    'message' => 'Rekod yang diminta tidak ditemui. Ia mungkin telah dipadamkan atau pautannya tidak sah.',
                    'error_code' => 'not_found',
                ], 404);
            }

            if ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();
                $defaults = [
                    402 => 'Pembayaran diperlukan untuk meneruskan.',
                    403 => 'Anda tiada kebenaran untuk tindakan ini.',
                    404 => 'Rekod yang diminta tidak ditemui. Ia mungkin telah dipadamkan atau pautannya tidak sah.',
                    405 => 'Tindakan ini tidak dibenarkan oleh pelayan.',
                    409 => 'Permintaan ini bercanggah dengan data semasa. Sila muat semula halaman.',
                    410 => 'Pautan ini telah tamat tempoh dan tidak lagi tersedia.',
                    419 => 'Halaman ini telah tamat tempoh. Sila muat semula halaman dan cuba lagi.',
                    422 => 'Maklumat yang dihantar tidak sah. Sila semak semula borang anda.',
                    423 => 'Rekod ini dikunci sementara. Sila cuba lagi.',
                    429 => 'Terlalu banyak cubaan dalam masa singkat. Sila tunggu sebentar sebelum cuba lagi.',
                    503 => 'Perkhidmatan sedang diselenggara. Sila cuba lagi sebentar nanti.',
                ];
                $message = trim($exception->getMessage());
                $fallback = $defaults[$status] ?? ($status >= 500
                    ? 'Ralat pelayan. Sila cuba lagi.'
                    : 'Permintaan tidak dapat diproses.');
                // Laravel's framework wording ("This action is unauthorized.") is not customer friendly.
                if ($message === '' || $message === ($defaults[$status] ?? null) || str_starts_with($message, 'This action is unauthorized')) {
                    $message = $fallback;
                }

                return response()->json([
                    'message' => $message,
                    'error_code' => $status >= 500 ? 'server_error' : 'http_'.$status,
                ], $status, $exception->getHeaders());
            }

            // Unexpected failure: keep the detail in the log, give the customer a traceable code.
            $reference = 'MD-'.strtoupper(Str::random(8));
            Log::error('Unhandled API exception', [
                'reference' => $reference,
                'method' => $request->method(),
                'path' => $request->path(),
                'exception' => $exception,
            ]);

            $message = config('app.debug')
                ? 'Ralat pelayan: '.$exception->getMessage().' (rujukan: '.$reference.')'
                : 'Ralat pelayan dalaman. Sila cuba lagi sebentar nanti. Jika masalah berterusan, hubungi sokongan dengan rujukan '.$reference.'.';

            return response()->json([
                'message' => $message,
                'error_code' => $reference,
            ], 500);
        });
    })->create();
