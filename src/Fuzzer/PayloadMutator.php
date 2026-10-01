<?php

declare(strict_types=1);

namespace EidCloud\ApiContractTest\Fuzzer;

class PayloadMutator
{
    /**
     * Generates a compliant baseline payload according to the provided schema.
     */
    public function generateBaseline(array $schema): mixed
    {
        $type = $schema['type'] ?? 'object';

        if (isset($schema['example'])) {
            return $schema['example'];
        }

        if (isset($schema['default'])) {
            return $schema['default'];
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && !empty($schema['enum'])) {
            return $schema['enum'][0];
        }

        return match ($type) {
            'string' => $this->generateSampleString($schema),
            'integer' => $this->generateSampleInteger($schema),
            'number' => $this->generateSampleNumber($schema),
            'boolean' => true,
            'array' => $this->generateSampleArray($schema),
            'object' => $this->generateSampleObject($schema),
            default => 'sample_value',
        };
    }

    private function generateSampleString(array $schema): string
    {
        $format = $schema['format'] ?? null;
        if ($format === 'email') {
            return 'qa-tester@example.com';
        }
        if ($format === 'uuid') {
            return '550e8400-e29b-41d4-a716-446655440000';
        }
        if ($format === 'date' || $format === 'date-time') {
            return '2026-10-01T12:00:00Z';
        }
        if ($format === 'uri' || $format === 'url') {
            return 'https://api.eidcloud.internal/v1';
        }

        $minLength = (int) ($schema['minLength'] ?? 1);
        return str_repeat('a', max(1, $minLength));
    }

    private function generateSampleInteger(array $schema): int
    {
        if (isset($schema['minimum'])) {
            return (int) $schema['minimum'];
        }
        if (isset($schema['maximum']) && 42 > (int) $schema['maximum']) {
            return (int) $schema['maximum'];
        }
        return 42;
    }

    private function generateSampleNumber(array $schema): float
    {
        if (isset($schema['minimum'])) {
            return (float) $schema['minimum'];
        }
        if (isset($schema['maximum']) && 19.99 > (float) $schema['maximum']) {
            return (float) $schema['maximum'];
        }
        return 19.99;
    }

    private function generateSampleArray(array $schema): array
    {
        $itemSchema = $schema['items'] ?? ['type' => 'string'];
        $minItems = (int) ($schema['minItems'] ?? 1);
        $count = max(1, $minItems);

        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->generateBaseline($itemSchema);
        }
        return $items;
    }

    private function generateSampleObject(array $schema): array
    {
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];
        $result = [];

        // Populate required properties first
        foreach ($required as $propName) {
            if (isset($properties[$propName])) {
                $result[$propName] = $this->generateBaseline($properties[$propName]);
            } else {
                $result[$propName] = 'required_default';
            }
        }

        // Also populate remaining properties for complete baseline
        foreach ($properties as $propName => $propSchema) {
            if (!array_key_exists($propName, $result) && is_array($propSchema)) {
                $result[$propName] = $this->generateBaseline($propSchema);
            }
        }

        return $result;
    }

    /**
     * Generates a comprehensive set of test cases for a single operation.
     *
     * @return array<int, TestCase>
     */
    public function generateOperationTestCases(array $operation): array
    {
        $testCases = [];
        $opId = $operation['operationId'];
        $method = $operation['method'];
        $pathTemplate = $operation['path'];
        $contentType = $operation['contentType'] ?? 'application/json';
        $bodySchema = $operation['requestBodySchema'];

        // 1. Generate Baseline Valid Test Case
        $baselineParams = $this->generateBaselineParameters($operation['parameters']);
        $baselineBody = $bodySchema !== null ? $this->generateBaseline($bodySchema) : null;
        $resolvedPath = $this->substitutePathParameters($pathTemplate, $baselineParams['path']);

        $defaultHeaders = [
            'Content-Type' => $contentType,
            'Accept' => 'application/json',
            'User-Agent' => 'EidCloud-Contract-Fuzzer/1.0',
        ];

        // Add valid mock auth if security requirements exist
        if (!empty($operation['security'])) {
            $defaultHeaders['Authorization'] = 'Bearer eidcloud_valid_mock_token_2026';
        }

        $testCases[] = new TestCase(
            operationId: $opId,
            method: $method,
            path: $resolvedPath,
            queryParams: $baselineParams['query'],
            headers: array_merge($defaultHeaders, $baselineParams['header']),
            body: $baselineBody,
            description: "Baseline valid contract request for {$method} {$pathTemplate}",
            mutationType: MutationType::BASELINE_VALID,
            expectedStatusCategory: '2xx',
            targetField: null
        );

        // 2. Generate Request Body Mutations (if body schema exists)
        if ($bodySchema !== null && is_array($baselineBody)) {
            // Type Mismatch Injections
            $testCases = array_merge(
                $testCases,
                $this->generateTypeMismatchMutations($operation, $baselineParams, $defaultHeaders, $baselineBody, $bodySchema)
            );

            // Boundary Limit Injections
            $testCases = array_merge(
                $testCases,
                $this->generateBoundaryLimitMutations($operation, $baselineParams, $defaultHeaders, $baselineBody, $bodySchema)
            );

            // Missing Required Fields
            $testCases = array_merge(
                $testCases,
                $this->generateMissingRequiredMutations($operation, $baselineParams, $defaultHeaders, $baselineBody, $bodySchema)
            );

            // Unexpected Property Injections
            $testCases = array_merge(
                $testCases,
                $this->generateUnexpectedPropertyMutations($operation, $baselineParams, $defaultHeaders, $baselineBody)
            );
        }

        // 3. Generate Auth Security Mutations (if endpoint defines security)
        if (!empty($operation['security'])) {
            $testCases = array_merge(
                $testCases,
                $this->generateAuthMutations($operation, $baselineParams, $defaultHeaders, $baselineBody)
            );
        }

        // 4. Generate Path and Query Parameter Boundary Injections
        $testCases = array_merge(
            $testCases,
            $this->generateParameterMutations($operation, $baselineParams, $defaultHeaders, $baselineBody)
        );

        return $testCases;
    }

    private function generateBaselineParameters(array $parameters): array
    {
        $path = [];
        $query = [];
        $header = [];

        foreach ($parameters as $param) {
            $in = $param['in'] ?? 'query';
            $name = $param['name'] ?? 'param';
            $schema = $param['schema'] ?? ['type' => 'string'];
            $val = $this->generateBaseline($schema);

            if ($in === 'path') {
                $path[$name] = (string) $val;
            } elseif ($in === 'query') {
                $query[$name] = (string) $val;
            } elseif ($in === 'header') {
                $header[$name] = (string) $val;
            }
        }

        return ['path' => $path, 'query' => $query, 'header' => $header];
    }

    private function substitutePathParameters(string $template, array $pathParams): string
    {
        $result = $template;
        foreach ($pathParams as $key => $val) {
            $result = str_replace('{' . $key . '}', rawurlencode((string) $val), $result);
        }
        // Fallback for any unreplaced path params
        return (string) preg_replace('/\{([^}]+)\}/', '1', $result);
    }

    /**
     * Type mismatch mutations: string in int, object in array, boolean in string.
     */
    private function generateTypeMismatchMutations(
        array $operation,
        array $params,
        array $headers,
        array $baselineBody,
        array $bodySchema
    ): array {
        $cases = [];
        $properties = $bodySchema['properties'] ?? [];

        foreach ($properties as $propName => $propSchema) {
            if (!is_array($propSchema)) {
                continue;
            }
            $targetType = $propSchema['type'] ?? 'string';
            $invalidValues = match ($targetType) {
                'integer', 'number' => [
                    'mismatch_string' => '__type_mismatch_string__',
                    'mismatch_array' => [1, 2, 3],
                    'mismatch_object' => ['invalid' => true],
                ],
                'string' => [
                    'mismatch_integer' => 12345678,
                    'mismatch_array' => ['invalid_sub_item'],
                    'mismatch_bool' => false,
                ],
                'boolean' => [
                    'mismatch_string' => 'not_a_boolean',
                    'mismatch_int' => 9999,
                ],
                'array' => [
                    'mismatch_string' => 'scalar_string_instead_of_array',
                    'mismatch_object' => ['key' => 'not_an_array'],
                ],
                default => [
                    'mismatch_scalar' => 999999,
                ],
            };

            foreach ($invalidValues as $label => $val) {
                $mutatedBody = $baselineBody;
                $mutatedBody[$propName] = $val;

                $cases[] = new TestCase(
                    operationId: $operation['operationId'],
                    method: $operation['method'],
                    path: $this->substitutePathParameters($operation['path'], $params['path']),
                    queryParams: $params['query'],
                    headers: $headers,
                    body: $mutatedBody,
                    description: "Type Mismatch [{$label}]: Injected invalid type into field '{$propName}' (expected {$targetType})",
                    mutationType: MutationType::TYPE_MISMATCH,
                    expectedStatusCategory: '4xx',
                    targetField: $propName
                );
            }
        }

        return $cases;
    }

    /**
     * Boundary limits: min/max overflows, empty/exceeding string lengths.
     */
    private function generateBoundaryLimitMutations(
        array $operation,
        array $params,
        array $headers,
        array $baselineBody,
        array $bodySchema
    ): array {
        $cases = [];
        $properties = $bodySchema['properties'] ?? [];

        foreach ($properties as $propName => $propSchema) {
            if (!is_array($propSchema)) {
                continue;
            }
            $type = $propSchema['type'] ?? 'string';

            if ($type === 'integer' || $type === 'number') {
                $boundaryMutations = [
                    'php_int_max_overflow' => PHP_INT_MAX,
                    'extreme_negative' => -999999999999999,
                ];

                if (isset($propSchema['minimum'])) {
                    $boundaryMutations['below_minimum'] = $propSchema['minimum'] - 1;
                }
                if (isset($propSchema['maximum'])) {
                    $boundaryMutations['above_maximum'] = $propSchema['maximum'] + 1;
                }

                foreach ($boundaryMutations as $boundaryLabel => $val) {
                    $mutated = $baselineBody;
                    $mutated[$propName] = $val;
                    $cases[] = new TestCase(
                        operationId: $operation['operationId'],
                        method: $operation['method'],
                        path: $this->substitutePathParameters($operation['path'], $params['path']),
                        queryParams: $params['query'],
                        headers: $headers,
                        body: $mutated,
                        description: "Boundary Limit [{$boundaryLabel}]: Value {$val} for field '{$propName}'",
                        mutationType: MutationType::BOUNDARY_LIMIT,
                        expectedStatusCategory: '4xx',
                        targetField: $propName
                    );
                }
            } elseif ($type === 'string') {
                $boundaryMutations = [
                    'massive_payload_10k' => str_repeat('X', 10000),
                ];

                if (isset($propSchema['minLength']) && $propSchema['minLength'] > 0) {
                    $boundaryMutations['below_min_length_empty'] = '';
                }
                if (isset($propSchema['maxLength'])) {
                    $boundaryMutations['above_max_length'] = str_repeat('Y', (int) $propSchema['maxLength'] + 10);
                }

                foreach ($boundaryMutations as $boundaryLabel => $val) {
                    $mutated = $baselineBody;
                    $mutated[$propName] = $val;
                    $cases[] = new TestCase(
                        operationId: $operation['operationId'],
                        method: $operation['method'],
                        path: $this->substitutePathParameters($operation['path'], $params['path']),
                        queryParams: $params['query'],
                        headers: $headers,
                        body: $mutated,
                        description: "Boundary Limit [{$boundaryLabel}]: String length boundary mutation for field '{$propName}'",
                        mutationType: MutationType::BOUNDARY_LIMIT,
                        expectedStatusCategory: '4xx',
                        targetField: $propName
                    );
                }
            }
        }

        return $cases;
    }

    /**
     * Missing required properties.
     */
    private function generateMissingRequiredMutations(
        array $operation,
        array $params,
        array $headers,
        array $baselineBody,
        array $bodySchema
    ): array {
        $cases = [];
        $required = $bodySchema['required'] ?? [];

        foreach ($required as $reqField) {
            $mutated = $baselineBody;
            unset($mutated[$reqField]);

            $cases[] = new TestCase(
                operationId: $operation['operationId'],
                method: $operation['method'],
                path: $this->substitutePathParameters($operation['path'], $params['path']),
                queryParams: $params['query'],
                headers: $headers,
                body: $mutated,
                description: "Missing Required Field: Removed required property '{$reqField}'",
                mutationType: MutationType::MISSING_REQUIRED,
                expectedStatusCategory: '4xx',
                targetField: $reqField
            );
        }

        return $cases;
    }

    /**
     * Unexpected extra properties injection.
     */
    private function generateUnexpectedPropertyMutations(
        array $operation,
        array $params,
        array $headers,
        array $baselineBody
    ): array {
        $mutated = $baselineBody;
        $mutated['__eidcloud_unexpected_extra_property__'] = 'contract_violation_probe';
        $mutated['__unexpected_nested__'] = ['probe' => true, 'depth' => 2];

        return [
            new TestCase(
                operationId: $operation['operationId'],
                method: $operation['method'],
                path: $this->substitutePathParameters($operation['path'], $params['path']),
                queryParams: $params['query'],
                headers: $headers,
                body: $mutated,
                description: "Unexpected Property Injection: Added undeclared fields to request body",
                mutationType: MutationType::UNEXPECTED_PROPERTY,
                expectedStatusCategory: '4xx',
                targetField: '__eidcloud_unexpected_extra_property__'
            )
        ];
    }

    /**
     * Auth mutations: missing token, expired mock JWT, malformed header.
     */
    private function generateAuthMutations(
        array $operation,
        array $params,
        array $headers,
        mixed $body
    ): array {
        $cases = [];
        $basePath = $this->substitutePathParameters($operation['path'], $params['path']);

        // Case A: Missing Authorization Header
        $noAuthHeaders = $headers;
        unset($noAuthHeaders['Authorization']);
        $cases[] = new TestCase(
            operationId: $operation['operationId'],
            method: $operation['method'],
            path: $basePath,
            queryParams: $params['query'],
            headers: $noAuthHeaders,
            body: $body,
            description: "Auth Mutation: Missing Authorization header on secured endpoint",
            mutationType: MutationType::MALFORMED_AUTH,
            expectedStatusCategory: '4xx',
            targetField: 'Authorization'
        );

        // Case B: Malformed Bearer
        $malformedHeaders = $headers;
        $malformedHeaders['Authorization'] = 'Bearer invalid.malformed.payload';
        $cases[] = new TestCase(
            operationId: $operation['operationId'],
            method: $operation['method'],
            path: $basePath,
            queryParams: $params['query'],
            headers: $malformedHeaders,
            body: $body,
            description: "Auth Mutation: Malformed non-JWT Bearer token",
            mutationType: MutationType::MALFORMED_AUTH,
            expectedStatusCategory: '4xx',
            targetField: 'Authorization'
        );

        // Case C: Expired Mock JWT (exp timestamp = 1516239022 in year 2018)
        $expiredHeaders = $headers;
        $expiredHeaders['Authorization'] = 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTYiLCJleHAiOjE1MTYyMzkwMjJ9.mock_signature';
        $cases[] = new TestCase(
            operationId: $operation['operationId'],
            method: $operation['method'],
            path: $basePath,
            queryParams: $params['query'],
            headers: $expiredHeaders,
            body: $body,
            description: "Auth Mutation: Expired JWT token timestamp",
            mutationType: MutationType::MALFORMED_AUTH,
            expectedStatusCategory: '4xx',
            targetField: 'Authorization'
        );

        return $cases;
    }

    /**
     * Parameter boundary/injection mutations.
     */
    private function generateParameterMutations(
        array $operation,
        array $params,
        array $headers,
        mixed $body
    ): array {
        $cases = [];

        // Fuzz path parameters if any
        foreach ($params['path'] as $paramName => $validVal) {
            $injectionValues = [
                'special_boundary_chars' => '!@#$%^&*()_+~`|}{[]:;?><',
                'url_encoded_traversal' => '..%2F..%2Fetc%2Fpasswd',
                'sql_syntax_boundary' => "' OR '1'='1",
                'oversized_path_param' => str_repeat('p', 500),
            ];

            foreach ($injectionValues as $label => $injectedVal) {
                $mutatedPathParams = $params['path'];
                $mutatedPathParams[$paramName] = $injectedVal;

                $cases[] = new TestCase(
                    operationId: $operation['operationId'],
                    method: $operation['method'],
                    path: $this->substitutePathParameters($operation['path'], $mutatedPathParams),
                    queryParams: $params['query'],
                    headers: $headers,
                    body: $body,
                    description: "Parameter Boundary [{$label}]: Injected into path param '{$paramName}'",
                    mutationType: MutationType::PARAMETER_INJECTION,
                    expectedStatusCategory: '4xx',
                    targetField: $paramName
                );
            }
        }

        // Fuzz query parameters if any
        foreach ($params['query'] as $qName => $qVal) {
            $mutatedQuery = $params['query'];
            $mutatedQuery[$qName] = "' OR '1'='1";

            $cases[] = new TestCase(
                operationId: $operation['operationId'],
                method: $operation['method'],
                path: $this->substitutePathParameters($operation['path'], $params['path']),
                queryParams: $mutatedQuery,
                headers: $headers,
                body: $body,
                description: "Parameter Boundary [syntax_probe]: Injected probe into query param '{$qName}'",
                mutationType: MutationType::PARAMETER_INJECTION,
                expectedStatusCategory: '4xx',
                targetField: $qName
            );
        }

        return $cases;
    }
}
