<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;

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
                ['Botlock-Error' => $exception->getMessage()]
            );
        }
        catch (\Throwable $throwable)
        {
            return static::createErrorResponse($request, 500, 'Internal Server Error', $throwable);
        }
    }

    public static function createErrorResponse(Request $request, int $statusCode, string $message, ?\Throwable $throwable = null): Response
    {
        $accept = strtolower($request->getHeader('Accept', ''));

        $headers = [
            'Botlock-Error' => $throwable?->getMessage() ?? $message,
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
}
