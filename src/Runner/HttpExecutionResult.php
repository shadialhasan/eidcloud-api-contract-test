<?php

declare(strict_types=1);

namespace EidCloud\ApiContractTest\Runner;

class HttpExecutionResult
{
    public function __construct(
        private string $url,
        private int $statusCode,
        private array $headers,
        private string $body,
        private float $latencyMs,
        private ?string $error = null
    ) {
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getLatencyMs(): float
    {
        return $this->latencyMs;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function isSuccessStatusCode(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    public function isServerError(): bool
    {
        return $this->statusCode >= 500 && $this->statusCode < 600;
    }

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'statusCode' => $this->statusCode,
            'headers' => $this->headers,
            'bodyLength' => strlen($this->body),
            'latencyMs' => round($this->latencyMs, 2),
            'error' => $this->error,
        ];
    }
}
