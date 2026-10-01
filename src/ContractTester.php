<?php

declare(strict_types=1);

namespace EidCloud\ApiContractTest;

use EidCloud\ApiContractTest\Fuzzer\PayloadMutator;
use EidCloud\ApiContractTest\Fuzzer\TestCase;
use EidCloud\ApiContractTest\Parser\OpenApiParser;
use EidCloud\ApiContractTest\Reporter\ContractReport;
use EidCloud\ApiContractTest\Runner\TestRunner;

class ContractTester
{
    private ?OpenApiParser $parser = null;
    private PayloadMutator $mutator;
    private TestRunner $runner;
    private string $targetUrl = 'http://localhost:8080';
    private int $concurrency = 5;
    private bool $mockMode = false;

    public function __construct(
        string $targetUrl = 'http://localhost:8080',
        int $concurrency = 5,
        bool $mockMode = false,
        ?callable $customHandler = null
    ) {
        $this->targetUrl = $targetUrl;
        $this->concurrency = $concurrency;
        $this->mockMode = $mockMode;
        $this->mutator = new PayloadMutator();
        $this->runner = new TestRunner(
            baseUrl: $this->targetUrl,
            concurrency: $this->concurrency,
            timeoutSeconds: 10,
            mockMode: $this->mockMode,
            customHandler: $customHandler
        );
    }

    public static function create(
        string $targetUrl = 'http://localhost:8080',
        int $concurrency = 5,
        bool $mockMode = false
    ): self {
        return new self($targetUrl, $concurrency, $mockMode);
    }

    public function loadSpecFromFile(string $filePath): self
    {
        $this->parser = OpenApiParser::fromFile($filePath);
        if ($this->targetUrl === 'http://localhost:8080') {
            $base = $this->parser->getBaseUrl();
            if (!empty($base)) {
                $this->setTargetUrl($base);
            }
        }
        return $this;
    }

    public function loadSpecFromString(string $json): self
    {
        $this->parser = OpenApiParser::fromString($json);
        return $this;
    }

    public function setTargetUrl(string $url): self
    {
        $this->targetUrl = rtrim($url, '/');
        $this->runner = new TestRunner(
            baseUrl: $this->targetUrl,
            concurrency: $this->concurrency,
            mockMode: $this->mockMode
        );
        return $this;
    }

    public function setConcurrency(int $concurrency): self
    {
        $this->concurrency = max(1, $concurrency);
        $this->runner = new TestRunner(
            baseUrl: $this->targetUrl,
            concurrency: $this->concurrency,
            mockMode: $this->mockMode
        );
        return $this;
    }

    public function setMockMode(bool $mockMode): self
    {
        $this->mockMode = $mockMode;
        $this->runner->setMockMode($mockMode);
        return $this;
    }

    public function setCustomHandler(?callable $handler): self
    {
        $this->runner->setCustomHandler($handler);
        return $this;
    }

    public function getParser(): ?OpenApiParser
    {
        return $this->parser;
    }

    /**
     * Generates all test cases from the loaded specification.
     *
     * @return array<int, TestCase>
     */
    public function generateTestCases(): array
    {
        if ($this->parser === null) {
            throw new \RuntimeException("No OpenAPI specification loaded. Call loadSpecFromFile() first.");
        }

        $operations = $this->parser->getOperations();
        $testCases = [];

        foreach ($operations as $op) {
            $opCases = $this->mutator->generateOperationTestCases($op);
            $testCases = array_merge($testCases, $opCases);
        }

        return $testCases;
    }

    /**
     * Executes all generated contract tests and returns the final report.
     *
     * @param ?callable(\EidCloud\ApiContractTest\Runner\TestResult, int, int): void $onProgress
     */
    public function run(?callable $onProgress = null): ContractReport
    {
        $testCases = $this->generateTestCases();
        $startTime = microtime(true);

        $results = $this->runner->runSuite($testCases, $onProgress);
        $duration = microtime(true) - $startTime;

        return new ContractReport(
            results: $results,
            durationSeconds: $duration,
            targetUrl: $this->targetUrl,
            specTitle: $this->parser ? $this->parser->getTitle() : 'OpenAPI'
        );
    }
}
