<?php

declare(strict_types=1);

namespace Aura\Api\Http;

final class JsonResponse
{
    /** @param array<string, mixed>|list<mixed> $payload */
    public static function send(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function error(string $code, string $message, int $status): never
    {
        self::send([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}

