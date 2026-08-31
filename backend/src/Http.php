<?php

declare(strict_types=1);

function jsonResponse(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $body,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    exit;
}

function errorResponse(string $code, string $message, int $status): never
{
    jsonResponse(
        [
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ],
        $status
    );
}

