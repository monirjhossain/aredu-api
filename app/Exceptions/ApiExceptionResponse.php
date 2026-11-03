<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiExceptionResponse
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return self::from($exception);
    }

    public static function from(Throwable $exception): JsonResponse
    {
        if ($exception instanceof ValidationException) {
            return ApiResponse::error(
                $exception->getMessage(),
                $exception->status,
                $exception->errors(),
            );
        }

        if ($exception instanceof AuthenticationException) {
            $message = $exception->getMessage();

            return ApiResponse::error(
                $message !== '' ? $message : 'Unauthenticated.',
                401,
            );
        }

        if ($exception instanceof AuthorizationException) {
            $message = $exception->getMessage();

            return ApiResponse::error(
                $message !== '' ? $message : 'This action is unauthorized.',
                403,
            );
        }

        if ($exception instanceof HttpExceptionInterface) {
            return ApiResponse::error(
                self::messageForStatus($exception->getStatusCode(), $exception->getMessage()),
                $exception->getStatusCode(),
            );
        }

        return ApiResponse::error(self::messageForStatus(500, $exception->getMessage()), 500);
    }

    private static function messageForStatus(int $status, string $exceptionMessage): string
    {
        if ($status >= 500 && ! config('app.debug')) {
            return 'Server error.';
        }

        if ($exceptionMessage !== '') {
            return $exceptionMessage;
        }

        return match ($status) {
            401 => 'Unauthenticated.',
            403 => 'This action is unauthorized.',
            404 => 'Not found.',
            429 => 'Too many requests.',
            default => 'Request failed.',
        };
    }
}
