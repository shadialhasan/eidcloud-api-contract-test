<?php

declare(strict_types=1);

namespace EidCloud\ApiContractTest\Parser;

use InvalidArgumentException;
use RuntimeException;

class OpenApiParser
{
    private array $spec;

    public function __construct(array $spec)
    {
        $this->spec = $spec;
    }

    public static function fromFile(string $filePath): self
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("OpenAPI specification file not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Unable to read specification file: {$filePath}");
        }

        return self::fromString($content);
    }

    public static function fromString(string $content): self
    {
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Attempt basic YAML-to-JSON fallback if simple JSON-like YAML or invalid JSON
            throw new InvalidArgumentException("Failed to parse OpenAPI JSON: " . json_last_error_msg());
        }

        return new self($data);
    }

    public function getRawSpec(): array
    {
        return $this->spec;
    }

    public function getVersion(): string
    {
        return (string) ($this->spec['openapi'] ?? $this->spec['swagger'] ?? '3.0.0');
    }

    public function getTitle(): string
    {
        return (string) ($this->spec['info']['title'] ?? 'OpenAPI Specification');
    }

    public function getBaseUrl(): string
    {
        if (!empty($this->spec['servers']) && is_array($this->spec['servers'])) {
            $first = $this->spec['servers'][0];
            if (is_array($first) && isset($first['url'])) {
                return rtrim($first['url'], '/');
            }
        }
        return '';
    }

    /**
     * Extracts normalized operations across all paths in the specification.
     *
     * @return array<int, array{
     *     path: string,
     *     method: string,
     *     operationId: string,
     *     summary: string,
     *     parameters: array,
     *     requestBodySchema: ?array,
     *     contentType: string,
     *     security: array,
     *     responses: array
     * }>
     */
    public function getOperations(): array
    {
        $operations = [];
        $paths = $this->spec['paths'] ?? [];

        foreach ($paths as $path => $methods) {
            if (!is_array($methods)) {
                continue;
            }

            $pathLevelParams = $methods['parameters'] ?? [];

            foreach ($methods as $method => $opData) {
                $httpMethod = strtoupper($method);
                if (!in_array($httpMethod, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'], true)) {
                    continue;
                }

                if (!is_array($opData)) {
                    continue;
                }

                $mergedParams = array_merge(
                    is_array($pathLevelParams) ? $pathLevelParams : [],
                    is_array($opData['parameters'] ?? null) ? $opData['parameters'] : []
                );

                $resolvedParams = [];
                foreach ($mergedParams as $p) {
                    $resolvedParams[] = $this->resolveRef($p);
                }

                $bodySchema = null;
                $contentType = 'application/json';
                if (isset($opData['requestBody'])) {
                    $resolvedBody = $this->resolveRef($opData['requestBody']);
                    $content = $resolvedBody['content'] ?? [];
                    if (isset($content['application/json']['schema'])) {
                        $bodySchema = $this->resolveRef($content['application/json']['schema']);
                        $contentType = 'application/json';
                    } elseif (!empty($content)) {
                        $firstType = array_key_first($content);
                        if ($firstType !== null && isset($content[$firstType]['schema'])) {
                            $bodySchema = $this->resolveRef($content[$firstType]['schema']);
                            $contentType = (string) $firstType;
                        }
                    }
                }

                $operations[] = [
                    'path' => (string) $path,
                    'method' => $httpMethod,
                    'operationId' => (string) ($opData['operationId'] ?? "{$httpMethod}_{$path}"),
                    'summary' => (string) ($opData['summary'] ?? ''),
                    'parameters' => $resolvedParams,
                    'requestBodySchema' => $bodySchema,
                    'contentType' => $contentType,
                    'security' => $opData['security'] ?? ($this->spec['security'] ?? []),
                    'responses' => $opData['responses'] ?? [],
                ];
            }
        }

        return $operations;
    }

    /**
     * Recursively resolves $ref references within the OpenAPI specification.
     */
    public function resolveRef(mixed $node, int $depth = 0): mixed
    {
        if ($depth > 20 || !is_array($node)) {
            return $node;
        }

        if (isset($node['$ref']) && is_string($node['$ref'])) {
            $refPath = $node['$ref'];
            if (str_starts_with($refPath, '#/')) {
                $parts = explode('/', substr($refPath, 2));
                $current = $this->spec;
                foreach ($parts as $part) {
                    $part = str_replace('~1', '/', str_replace('~0', '~', $part));
                    if (is_array($current) && array_key_exists($part, $current)) {
                        $current = $current[$part];
                    } else {
                        return $node; // Return unresolved node if reference not found
                    }
                }
                // Merge any siblings if specified (OpenAPI 3.1 allows sibling properties with $ref)
                $merged = $this->resolveRef($current, $depth + 1);
                if (is_array($merged)) {
                    $siblings = $node;
                    unset($siblings['$ref']);
                    return array_replace_recursive($merged, $siblings);
                }
                return $merged;
            }
        }

        $resolved = [];
        foreach ($node as $key => $value) {
            $resolved[$key] = $this->resolveRef($value, $depth + 1);
        }

        return $resolved;
    }
}
