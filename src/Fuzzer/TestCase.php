<?php

declare(strict_types=1);

namespace EidCloud\ApiContractTest\Fuzzer;

class TestCase
{
    private string $id;
    private string $operationId;
    private string $method;
    private string $path;
    private array $queryParams;
    private array $headers;
    private mixed $body;
    private string $description;
    private MutationType $mutationType;
    private string $expectedStatusCategory; // e.g. '2xx' or '4xx'
    private ?string $targetField;

    public function __construct(
        string $operationId,
        string $method,
        string $path,
        array $queryParams,
        array $headers,
        mixed $body,
        string $description,
        MutationType $mutationType,
        string $expectedStatusCategory = '4xx',
        ?string $targetField = null
    ) {
        $this->id = uniqid('tc_', true);
        $this->operationId = $operationId;
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->queryParams = $queryParams;
        $this->headers = $headers;
        $this->body = $body;
        $this->description = $description;
        $this->mutationType = $mutationType;
        $this->expectedStatusCategory = $expectedStatusCategory;
        $this->targetField = $targetField;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOperationId(): string
    {
        return $this->operationId;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    public function getFormattedBody(): string
    {
        if (is_null($this->body)) {
            return '';
        }
        if (is_string($this->body)) {
            return $this->body;
        }
        return (string) json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getMutationType(): MutationType
    {
        return $this->mutationType;
    }

    public function getExpectedStatusCategory(): string
    {
        return $this->expectedStatusCategory;
    }

    public function getTargetField(): ?string
    {
        return $this->targetField;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'operationId' => $this->operationId,
            'method' => $this->method,
            'path' => $this->path,
            'queryParams' => $this->queryParams,
            'headers' => $this->headers,
            'body' => $this->body,
            'description' => $this->description,
            'mutationType' => $this->mutationType->value,
            'expectedStatusCategory' => $this->expectedStatusCategory,
            'targetField' => $this->targetField,
        ];
    }
}
