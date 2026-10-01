<?php

declare(strict_types=1);

namespace EidCloud\ApiContractTest\Runner;

use EidCloud\ApiContractTest\Fuzzer\MutationType;
use EidCloud\ApiContractTest\Fuzzer\TestCase;

class TestRunner
{
    private string $baseUrl;
    private int $concurrency;
    private int $timeoutSeconds;
    private bool $mockMode;
    /** @var ?callable(TestCase): HttpExecutionResult */
    private $customHandler;

    public function __construct(
        string $baseUrl = 'http://localhost:8080',
        int $concurrency = 5,
        int $timeoutSeconds = 10,
        bool $mockMode = false,
        ?callable $customHandler = null
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->concurrency = max(1, $concurrency);
        $this->timeoutSeconds = $timeoutSeconds;
        $this->mockMode = $mockMode;
        $this->customHandler = $customHandler;
    }

    public function setCustomHandler(?callable $handler): void
    {
        $this->customHandler = $handler;
    }

    public function setMockMode(bool $mockMode): void
    {
        $this->mockMode = $mockMode;
    }

    /**
     * Executes an array of test cases concurrently in batches.
     *
     * @param array<int, TestCase> $testCases
     * @param ?callable(TestResult, int, int): void $onProgress
     * @return array<int, TestResult>
     */
    public function runSuite(array $testCases, ?callable $onProgress = null): array
    {
        $results = [];
        $total = count($testCases);
        $chunks = array_chunk($testCases, $this->concurrency);
        $processed = 0;

        foreach ($chunks as $chunk) {
            $chunkResults = $this->runBatch($chunk);
            foreach ($chunkResults as $res) {
                $results[] = $res;
                $processed++;
                if ($onProgress !== null) {
                    $onProgress($res, $processed, $total);
                }
            }
        }

        return $results;
    }

    /**
     * Runs a single batch of test cases concurrently.
     *
     * @param array<int, TestCase> $batch
     * @return array<int, TestResult>
     */
    public function runBatch(array $batch): array
    {
        if ($this->customHandler !== null) {
            $results = [];
            foreach ($batch as $testCase) {
                $httpRes = ($this->customHandler)($testCase);
                $results[] = new TestResult($testCase, $httpRes);
            }
            return $results;
        }

        if ($this->mockMode) {
            $results = [];
            foreach ($batch as $testCase) {
                $httpRes = $this->simulateMockResponse($testCase);
                $results[] = new TestResult($testCase, $httpRes);
            }
            return $results;
        }

        // Live execution via curl_multi
        return $this->executeCurlMulti($batch);
    }

    private function simulateMockResponse(TestCase $testCase): HttpExecutionResult
    {
        $start = microtime(true);
        $mutation = $testCase->getMutationType();
        $targetField = $testCase->getTargetField();

        // Simulate intelligent API contract validation server
        if ($mutation === MutationType::BASELINE_VALID) {
            $status = 200;
            $body = json_encode([
                'status' => 'success',
                'message' => 'Contract verified successfully',
                'operationId' => $testCase->getOperationId(),
            ]);
        } elseif ($mutation === MutationType::MALFORMED_AUTH) {
            $status = 401;
            $body = json_encode([
                'error' => 'Unauthorized',
                'detail' => 'Invalid or expired authorization token',
            ]);
        } elseif ($mutation === MutationType::TYPE_MISMATCH) {
            $status = 422;
            $body = json_encode([
                'error' => 'Unprocessable Entity',
                'field' => $targetField,
                'detail' => "Schema validation error: type mismatch on field '{$targetField}'",
            ]);
        } elseif ($mutation === MutationType::MISSING_REQUIRED) {
            $status = 400;
            $body = json_encode([
                'error' => 'Bad Request',
                'field' => $targetField,
                'detail' => "Missing required property: '{$targetField}'",
            ]);
        } elseif ($mutation === MutationType::BOUNDARY_LIMIT) {
            $status = 422;
            $body = json_encode([
                'error' => 'Unprocessable Entity',
                'field' => $targetField,
                'detail' => "Constraint violation: boundary limit exceeded on field '{$targetField}'",
            ]);
        } elseif ($mutation === MutationType::UNEXPECTED_PROPERTY) {
            $status = 400;
            $body = json_encode([
                'error' => 'Bad Request',
                'detail' => 'Undeclared additional properties forbidden by schema contract',
            ]);
        } else {
            // Parameter injection boundary
            $status = 400;
            $body = json_encode([
                'error' => 'Bad Request',
                'detail' => 'Malformed parameter syntax rejected by API gateway',
            ]);
        }

        $latency = (microtime(true) - $start) * 1000 + mt_rand(2, 12);

        return new HttpExecutionResult(
            url: $this->buildFullUrl($testCase),
            statusCode: $status,
            headers: ['Content-Type' => 'application/json'],
            body: (string) $body,
            latencyMs: (float) $latency,
            error: null
        );
    }

    private function buildFullUrl(TestCase $testCase): string
    {
        $url = $this->baseUrl . '/' . ltrim($testCase->getPath(), '/');
        $query = $testCase->getQueryParams();
        if (!empty($query)) {
            $queryString = http_build_query($query);
            $url .= (str_contains($url, '?') ? '&' : '?') . $queryString;
        }
        return $url;
    }

    /**
     * Executes requests concurrently using PHP's native curl_multi engine.
     *
     * @param array<int, TestCase> $batch
     * @return array<int, TestResult>
     */
    private function executeCurlMulti(array $batch): array
    {
        if (!function_exists('curl_multi_init')) {
            // Fallback to synchronous file_get_contents or single curl
            return $this->executeSynchronousFallback($batch);
        }

        $mh = curl_multi_init();
        $handles = [];
        $startTimes = [];

        foreach ($batch as $index => $testCase) {
            $ch = curl_init();
            $fullUrl = $this->buildFullUrl($testCase);

            $headers = [];
            foreach ($testCase->getHeaders() as $k => $v) {
                $headers[] = "{$k}: {$v}";
            }

            curl_setopt($ch, CURLOPT_URL, $fullUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $testCase->getMethod());
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            $body = $testCase->getFormattedBody();
            if (!empty($body) && in_array($testCase->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            curl_multi_add_handle($mh, $ch);
            $handles[$index] = $ch;
            $startTimes[$index] = microtime(true);
        }

        // Execute handles concurrently
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.05);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($batch as $index => $testCase) {
            $ch = $handles[$index];
            $latency = (microtime(true) - $startTimes[$index]) * 1000;
            $rawResponse = curl_multi_getcontent($ch);
            $err = curl_error($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

            $responseHeaders = [];
            $responseBody = '';

            if (is_string($rawResponse) && strlen($rawResponse) >= $headerSize) {
                $headerStr = substr($rawResponse, 0, $headerSize);
                $responseBody = substr($rawResponse, $headerSize);
                foreach (explode("\r\n", $headerStr) as $line) {
                    if (str_contains($line, ':')) {
                        [$hKey, $hVal] = explode(':', $line, 2);
                        $responseHeaders[trim($hKey)] = trim($hVal);
                    }
                }
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $httpRes = new HttpExecutionResult(
                url: $this->buildFullUrl($testCase),
                statusCode: $statusCode,
                headers: $responseHeaders,
                body: $responseBody,
                latencyMs: $latency,
                error: !empty($err) ? $err : null
            );

            $results[] = new TestResult($testCase, $httpRes);
        }

        curl_multi_close($mh);
        return $results;
    }

    private function executeSynchronousFallback(array $batch): array
    {
        $results = [];
        foreach ($batch as $testCase) {
            $start = microtime(true);
            $url = $this->buildFullUrl($testCase);
            $headers = [];
            foreach ($testCase->getHeaders() as $k => $v) {
                $headers[] = "{$k}: {$v}";
            }

            $opts = [
                'http' => [
                    'method' => $testCase->getMethod(),
                    'header' => implode("\r\n", $headers),
                    'content' => $testCase->getFormattedBody(),
                    'timeout' => $this->timeoutSeconds,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ]
            ];

            $context = stream_context_create($opts);
            $body = @file_get_contents($url, false, $context);
            $latency = (microtime(true) - $start) * 1000;

            $statusCode = 0;
            $resHeaders = [];
            if (isset($http_response_header)) {
                if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
                    $statusCode = (int) $m[1];
                }
                foreach ($http_response_header as $line) {
                    if (str_contains($line, ':')) {
                        [$hk, $hv] = explode(':', $line, 2);
                        $resHeaders[trim($hk)] = trim($hv);
                    }
                }
            }

            $httpRes = new HttpExecutionResult(
                url: $url,
                statusCode: $statusCode,
                headers: $resHeaders,
                body: $body !== false ? $body : '',
                latencyMs: $latency,
                error: $body === false ? 'Connection error' : null
            );

            $results[] = new TestResult($testCase, $httpRes);
        }
        return $results;
    }
}
