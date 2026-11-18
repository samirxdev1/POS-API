<?php

namespace Api\Core;

class Response
{
    private int $statusCode = 200;
    private array $headers = [
        'Content-Type' => 'application/json; charset=utf-8',
    ];
    private array $payload = [];

    public function __construct(int $statusCode = 200, array $payload = [])
    {
        $this->statusCode = $statusCode;
        $this->payload = $payload;
    }

    public static function success(mixed $data = null, string $message = 'Success', ?array $meta = null, int $statusCode = 200): self
    {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return new self($statusCode, $payload);
    }

    public static function created(mixed $data = null, string $message = 'Created successfully'): self
    {
        return self::success($data, $message, null, 201);
    }

    public static function error(string $message = 'An error occurred', int $statusCode = 400, mixed $errors = null): self
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return new self($statusCode, $payload);
    }

    public static function unauthorized(string $message = 'Unauthorized: Missing or invalid API token'): self
    {
        return self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden: Token is inactive or has insufficient scope'): self
    {
        return self::error($message, 403);
    }

    public static function notFound(string $message = 'Resource not found'): self
    {
        return self::error($message, 404);
    }

    public static function validationError(mixed $errors, string $message = 'Validation failed'): self
    {
        return self::error($message, 422, $errors);
    }

    public static function tooManyRequests(string $message = 'Too many requests. Please slow down.'): self
    {
        return self::error($message, 429);
    }

    public static function serverError(string $message = 'Internal server error', mixed $errors = null): self
    {
        return self::error($message, 500, $errors);
    }

    public function header(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        echo json_encode($this->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

