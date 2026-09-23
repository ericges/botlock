<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;

/**
 * Turns exceptions from the rest of the stack into responses. Unexpected
 * throwables are logged via error_log() and answered with a generic 500;
 * their message never reaches the client.
 */
final readonly class ErrorMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        try
        {
            return $next($request);
        }
        catch (JsonResponseException $exception)
        {
            return new Response\JsonResponse(
                $exception->getCode() ?: 500,
                $exception->getData(),
                ['Botlock-Error' => self::headerSafe($exception->getMessage())]
            );
        }
        catch (\Throwable $throwable)
        {
            \error_log(\sprintf(
                'Botlock: %s: %s in %s:%d',
                $throwable::class,
                $throwable->getMessage(),
                $throwable->getFile(),
                $throwable->getLine(),
            ));

            return static::createErrorResponse($request, 500, 'Internal Server Error');
        }
    }

    public static function createErrorResponse(Request $request, int $statusCode, string $message): Response
    {
        $accept = strtolower($request->getHeader('Accept', ''));

        $headers = [
            'Botlock-Error' => self::headerSafe($message),
        ];

        if (\str_contains($accept, 'application/json')) {
            return new Response\JsonResponse($statusCode, ['ok' => false, 'error' => $message], $headers);
        }

        if (\str_contains($accept, 'text/html')) {
            $html = \sprintf(
                '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head>' .
                '<body><h1>Error %d</h1><p>%s</p></body></html>',
                $statusCode,
                \htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
            return new Response\HtmlResponse($statusCode, $html, $headers);
        }

        return new Response($statusCode, $headers + ['Content-Type' => 'text/plain; charset=utf-8'], $message);
    }

    /**
     * Header values must not contain line breaks (header injection).
     */
    private static function headerSafe(string $value): string
    {
        return \strtr($value, ["\r" => ' ', "\n" => ' ']);
    }
}
