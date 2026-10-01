<?php

declare(strict_types=1);

namespace EidCloud\ApiContractTest\Runner;

use EidCloud\ApiContractTest\Fuzzer\TestCase;

class TestResult
{
    private bool $passed;
    private ?string $breachType;
    private string $message;

    public const BREACH_CONTRACT_BYPASS = 'CONTRACT_BYPASS';
    public const BREACH_SERVER_CRASH = 'SERVER_CRASH';
    public const BREACH_BASELINE_FAILURE = 'BASELINE_FAILURE';

    public function __construct(
        private TestCase $testCase,
        private HttpExecutionResult $httpResult
    ) {
        $this->evaluate();
    }

    private function evaluate(): void
    {
        $status = $this->httpResult->getStatusCode();
        $expectedCategory = $this->testCase->getExpectedStatusCategory();

        if ($this->httpResult->getError() !== null && $status === 0) {
            $this->passed = false;
            $this->breachType = self::BREACH_SERVER_CRASH;
            $this->message = "Connection / Network Failure: " . $this->httpResult->getError();
            return;
        }

        if ($expectedCategory === '2xx') {
            if ($status >= 200 && $status < 300) {
                $this->passed = true;
                $this->breachType = null;
                $this->message = "Compliant: Received {$status} for valid baseline contract request.";
            } else {
                $this->passed = false;
                $this->breachType = self::BREACH_BASELINE_FAILURE;
                $this->message = "Baseline Failure: Expected 2xx, received HTTP {$status}.";
            }
        } else {
            // Expected 4xx
            if ($status >= 400 && $status < 500) {
                $this->passed = true;
                $this->breachType = null;
                $this->message = "Compliant: Successfully rejected invalid/mutated payload with HTTP {$status}.";
            } elseif ($status >= 500) {
                $this->passed = false;
                $this->breachType = self::BREACH_SERVER_CRASH;
                $this->message = "Server Crash Breach: Server crashed with HTTP {$status} instead of validating input gracefully.";
            } elseif ($status >= 200 && $status < 300) {
                $this->passed = false;
                $this->breachType = self::BREACH_CONTRACT_BYPASS;
                $this->message = "Contract Bypass Breach: Server accepted mutated/invalid payload with HTTP {$status}!";
            } else {
                $this->passed = false;
                $this->breachType = self::BREACH_CONTRACT_BYPASS;
                $this->message = "Unexpected HTTP status: Received {$status} (expected 4xx client rejection).";
            }
        }
    }

    public function getTestCase(): TestCase
    {
        return $this->testCase;
    }

    public function getHttpResult(): HttpExecutionResult
    {
        return $this->httpResult;
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    public function isBreach(): bool
    {
        return !$this->passed;
    }

    public function getBreachType(): ?string
    {
        return $this->breachType;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function toArray(): array
    {
        return [
            'testCaseId' => $this->testCase->getId(),
            'operationId' => $this->testCase->getOperationId(),
            'method' => $this->testCase->getMethod(),
            'path' => $this->testCase->getPath(),
            'mutationType' => $this->testCase->getMutationType()->value,
            'description' => $this->testCase->getDescription(),
            'expectedStatus' => $this->testCase->getExpectedStatusCategory(),
            'actualStatus' => $this->httpResult->getStatusCode(),
            'latencyMs' => $this->httpResult->getLatencyMs(),
            'passed' => $this->passed,
            'breachType' => $this->breachType,
            'message' => $this->message,
        ];
    }
}
