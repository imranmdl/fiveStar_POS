<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Every API response uses the contract fixed by the SRS:
 *   { "success": bool, "message": string, "data": object|array, "errors": array }
 */
final class Response
{
    private ?string $filePath = null;

    /** @param array<string, string> $headers */
    private function __construct(
        private readonly int $status,
        private readonly array $payload,
        private array $headers = [],
    ) {
    }

    /**
     * Streams a file from disk as the response body instead of JSON — the
     * one exception to this class's usual all-JSON contract, needed for
     * backup downloads. `$path` is never taken from request input directly;
     * callers resolve it through a service that validates the filename
     * first (see BackupService::pathFor()).
     */
    public static function file(string $path, string $downloadName, string $contentType = 'application/octet-stream'): self
    {
        $response = new self(200, []);
        $response->filePath = $path;
        $response->headers['Content-Type'] = $contentType;
        $response->headers['Content-Disposition'] = 'attachment; filename="' . $downloadName . '"';
        $response->headers['Content-Length'] = (string) filesize($path);

        return $response;
    }

    public static function success(
        mixed $data = [],
        string $message = '',
        int $status = 200,
        array $meta = [],
    ): self {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data === null ? [] : $data,
            'errors' => [],
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return new self($status, $payload);
    }

    public static function created(mixed $data = [], string $message = 'Created successfully'): self
    {
        return self::success($data, $message, 201);
    }

    /** @param array<string, array<int, string>>|array<int, string> $errors */
    public static function error(string $message, int $status = 400, array $errors = []): self
    {
        return new self($status, [
            'success' => false,
            'message' => $message,
            'data' => [],
            'errors' => $errors,
        ]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function send(): void
    {
        if ($this->filePath !== null) {
            if (!headers_sent()) {
                http_response_code($this->status);
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: DENY');

                foreach ($this->headers as $name => $value) {
                    header($name . ': ' . $value);
                }
            }

            readfile($this->filePath);

            return;
        }

        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: no-referrer');

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo json_encode(
            $this->payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
