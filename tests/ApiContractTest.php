<?php

declare(strict_types=1);

namespace EidCloud\ApiContractTest\Tests;

use EidCloud\ApiContractTest\ContractTester;
use EidCloud\ApiContractTest\Fuzzer\MutationType;
use EidCloud\ApiContractTest\Fuzzer\PayloadMutator;
use EidCloud\ApiContractTest\Fuzzer\TestCase;
use EidCloud\ApiContractTest\Parser\OpenApiParser;
use EidCloud\ApiContractTest\Reporter\ContractReport;
use EidCloud\ApiContractTest\Runner\HttpExecutionResult;
use EidCloud\ApiContractTest\Runner\TestResult;
use EidCloud\ApiContractTest\Runner\TestRunner;

class ApiContractTest
{
    private string $fixtureFile;

    public function __construct()
    {
        $this->fixtureFile = __DIR__ . '/fixtures/petstore.json';
    }

    public function runAll(): array
    {
        $methods = get_class_methods($this);
        $testMethods = array_filter($methods, fn($m) => str_starts_with($m, 'test'));
        $results = [];

        foreach ($testMethods as $test) {
            try {
                $this->$test();
                $results[$test] = ['status' => 'PASS', 'error' => null];
            } catch (\Throwable $e) {
                $results[$test] = [
                    'status' => 'FAIL',
                    'error' => $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine()
                ];
            }
        }

        return $results;
    }

    private function assertTrue(bool $condition, string $msg = 'Assertion failed'): void
    {
        if (!$condition) {
            throw new \RuntimeException($msg);
        }
    }

    private function assertEquals(mixed $expected, mixed $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            $expectedStr = var_export($expected, true);
            $actualStr = var_export($actual, true);
            throw new \RuntimeException("{$msg} Expected {$expectedStr} but got {$actualStr}");
        }
    }

    public function testParserLoadsAndResolvesReferences(): void
    {
        $parser = OpenApiParser::fromFile($this->fixtureFile);
        $this->assertEquals('3.0.3', $parser->getVersion(), 'Version match');
        $this->assertEquals('Swagger Petstore - EidCloud Contract Evaluation', $parser->getTitle());
        $this->assertEquals('http://localhost:8080/v1', $parser->getBaseUrl());

        $operations = $parser->getOperations();
        $this->assertTrue(count($operations) >= 4, 'Should extract at least 4 operations');

        $createPet = null;
        foreach ($operations as $op) {
            if ($op['operationId'] === 'createPet') {
                $createPet = $op;
                break;
            }
        }

        $this->assertTrue($createPet !== null, 'createPet operation must exist');
        $this->assertEquals('POST', $createPet['method']);
        $this->assertTrue(!empty($createPet['requestBodySchema']), 'createPet must have resolved requestBody schema');
        $this->assertEquals('object', $createPet['requestBodySchema']['type'] ?? null);
        $this->assertTrue(isset($createPet['requestBodySchema']['properties']['name']), 'Resolved Pet schema must contain name property');
    }

    public function testBaselineGenerationForSchemas(): void
    {
        $mutator = new PayloadMutator();
        $schema = [
            'type' => 'object',
            'required' => ['id', 'email', 'age'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 10],
                'email' => ['type' => 'string', 'format' => 'email'],
                'age' => ['type' => 'integer', 'maximum' => 120],
                'notes' => ['type' => 'string', 'minLength' => 5],
            ]
        ];

        $baseline = $mutator->generateBaseline($schema);
        $this->assertTrue(is_array($baseline), 'Baseline must be an array');
        $this->assertEquals(10, $baseline['id']);
        $this->assertEquals('qa-tester@example.com', $baseline['email']);
        $this->assertEquals(42, $baseline['age']);
        $this->assertTrue(strlen($baseline['notes']) >= 5, 'notes must adhere to minLength');
    }

    public function testTypeMismatchMutations(): void
    {
        $parser = OpenApiParser::fromFile($this->fixtureFile);
        $operations = $parser->getOperations();
        $mutator = new PayloadMutator();

        $createPetOp = null;
        foreach ($operations as $op) {
            if ($op['operationId'] === 'createPet') {
                $createPetOp = $op;
                break;
            }
        }

        $testCases = $mutator->generateOperationTestCases($createPetOp);
        $typeMismatchCases = array_filter(
            $testCases,
            fn(TestCase $tc) => $tc->getMutationType() === MutationType::TYPE_MISMATCH
        );

        $this->assertTrue(count($typeMismatchCases) > 0, 'Must generate type mismatch mutations');

        // Check that at least one mutated body contains string for an integer field
        $foundIntMismatch = false;
        foreach ($typeMismatchCases as $tc) {
            if ($tc->getTargetField() === 'id' && is_string($tc->getBody()['id'] ?? null)) {
                $foundIntMismatch = true;
                break;
            }
        }
        $this->assertTrue($foundIntMismatch, 'Should mutate integer id to string');
    }

    public function testBoundaryLimitMutations(): void
    {
        $parser = OpenApiParser::fromFile($this->fixtureFile);
        $operations = $parser->getOperations();
        $mutator = new PayloadMutator();

        $createPetOp = null;
        foreach ($operations as $op) {
            if ($op['operationId'] === 'createPet') {
                $createPetOp = $op;
                break;
            }
        }

        $testCases = $mutator->generateOperationTestCases($createPetOp);
        $boundaryCases = array_filter(
            $testCases,
            fn(TestCase $tc) => $tc->getMutationType() === MutationType::BOUNDARY_LIMIT
        );

        $this->assertTrue(count($boundaryCases) > 0, 'Must generate boundary limit mutations');

        $foundOverflow = false;
        foreach ($boundaryCases as $tc) {
            if ($tc->getTargetField() === 'id' && ($tc->getBody()['id'] ?? 0) === PHP_INT_MAX) {
                $foundOverflow = true;
                break;
            }
        }
        $this->assertTrue($foundOverflow, 'Must test PHP_INT_MAX boundary overflow on id');
    }

    public function testMissingRequiredPropertiesMutations(): void
    {
        $parser = OpenApiParser::fromFile($this->fixtureFile);
        $operations = $parser->getOperations();
        $mutator = new PayloadMutator();

        $createPetOp = null;
        foreach ($operations as $op) {
            if ($op['operationId'] === 'createPet') {
                $createPetOp = $op;
                break;
            }
        }

        $testCases = $mutator->generateOperationTestCases($createPetOp);
        $missingReqCases = array_filter(
            $testCases,
            fn(TestCase $tc) => $tc->getMutationType() === MutationType::MISSING_REQUIRED
        );

        $this->assertTrue(count($missingReqCases) >= 2, 'Must generate missing required cases for id and name');

        $fields = array_map(fn(TestCase $tc) => $tc->getTargetField(), $missingReqCases);
        $this->assertTrue(in_array('id', $fields, true), 'Missing id case must exist');
        $this->assertTrue(in_array('name', $fields, true), 'Missing name case must exist');
    }

    public function testUnexpectedPropertyInjections(): void
    {
        $parser = OpenApiParser::fromFile($this->fixtureFile);
        $operations = $parser->getOperations();
        $mutator = new PayloadMutator();

        $createPetOp = null;
        foreach ($operations as $op) {
            if ($op['operationId'] === 'createPet') {
                $createPetOp = $op;
                break;
            }
        }

        $testCases = $mutator->generateOperationTestCases($createPetOp);
        $unexpectedCases = array_filter(
            $testCases,
            fn(TestCase $tc) => $tc->getMutationType() === MutationType::UNEXPECTED_PROPERTY
        );

        $this->assertTrue(count($unexpectedCases) >= 1, 'Must generate unexpected property mutations');
        $tc = reset($unexpectedCases);
        $this->assertTrue(isset($tc->getBody()['__eidcloud_unexpected_extra_property__']), 'Must contain unexpected property key');
    }

    public function testMalformedAndExpiredAuthMutations(): void
    {
        $parser = OpenApiParser::fromFile($this->fixtureFile);
        $operations = $parser->getOperations();
        $mutator = new PayloadMutator();

        $createPetOp = null;
        foreach ($operations as $op) {
            if ($op['operationId'] === 'createPet') {
                $createPetOp = $op;
                break;
            }
        }

        $testCases = $mutator->generateOperationTestCases($createPetOp);
        $authCases = array_filter(
            $testCases,
            fn(TestCase $tc) => $tc->getMutationType() === MutationType::MALFORMED_AUTH
        );

        $this->assertTrue(count($authCases) >= 3, 'Must generate at least 3 auth mutations (missing, malformed, expired JWT)');
    }

    public function testParameterInjections(): void
    {
        $parser = OpenApiParser::fromFile($this->fixtureFile);
        $operations = $parser->getOperations();
        $mutator = new PayloadMutator();

        $showPetOp = null;
        foreach ($operations as $op) {
            if ($op['operationId'] === 'showPetById') {
                $showPetOp = $op;
                break;
            }
        }

        $testCases = $mutator->generateOperationTestCases($showPetOp);
        $paramCases = array_filter(
            $testCases,
            fn(TestCase $tc) => $tc->getMutationType() === MutationType::PARAMETER_INJECTION
        );

        $this->assertTrue(count($paramCases) > 0, 'Must generate parameter injections for path parameters');
    }

    public function testExecutionWithMockSimulator(): void
    {
        $tester = ContractTester::create('http://localhost:8080/v1', concurrency: 4, mockMode: true);
        $tester->loadSpecFromFile($this->fixtureFile);

        $report = $tester->run();
        $this->assertTrue($report->getTotalTests() > 0, 'Total tests should be > 0');
        $this->assertEquals(100.0, $report->getComplianceScore(), 'Mock simulator should be 100% compliant');
        $this->assertEquals(0, $report->getBreachTests(), 'Zero breaches in compliant mock mode');
    }

    public function testContractBreachDetectionBypassAndCrash(): void
    {
        $tcBypass = new TestCase(
            operationId: 'op1',
            method: 'POST',
            path: '/pets',
            queryParams: [],
            headers: [],
            body: ['invalid' => true],
            description: 'Testing bypass',
            mutationType: MutationType::TYPE_MISMATCH,
            expectedStatusCategory: '4xx'
        );

        // Server returned 200 on mutated payload => Breach: Contract Bypass!
        $httpBypass = new HttpExecutionResult('http://test/pets', 200, [], '{}', 15.0);
        $resBypass = new TestResult($tcBypass, $httpBypass);
        $this->assertTrue(!$resBypass->isPassed(), 'Must flag as breach');
        $this->assertEquals(TestResult::BREACH_CONTRACT_BYPASS, $resBypass->getBreachType());

        // Server crashed with 500 => Breach: Server Crash!
        $httpCrash = new HttpExecutionResult('http://test/pets', 500, [], 'Internal Server Error', 20.0);
        $resCrash = new TestResult($tcBypass, $httpCrash);
        $this->assertTrue(!$resCrash->isPassed(), 'Must flag as breach');
        $this->assertEquals(TestResult::BREACH_SERVER_CRASH, $resCrash->getBreachType());
    }

    public function testReportMetricsAndFormatting(): void
    {
        $tc1 = new TestCase('op1', 'GET', '/pets', [], [], null, 'Valid', MutationType::BASELINE_VALID, '2xx');
        $tc2 = new TestCase('op2', 'POST', '/pets', [], [], ['bad'], 'Invalid', MutationType::TYPE_MISMATCH, '4xx');

        $r1 = new TestResult($tc1, new HttpExecutionResult('http://test/pets', 200, [], '', 10.0));
        $r2 = new TestResult($tc2, new HttpExecutionResult('http://test/pets', 422, [], '', 12.0));

        $report = new ContractReport([$r1, $r2], durationSeconds: 0.25, targetUrl: 'http://test', specTitle: 'Test Petstore');

        $this->assertEquals(2, $report->getTotalTests());
        $this->assertEquals(2, $report->getPassedTests());
        $this->assertEquals(100.0, $report->getComplianceScore());

        $table = $report->renderCliTable();
        $this->assertTrue(str_contains($table, 'COMPLIANCE SCORE: 100.0%'), 'Table renders score');

        $markdown = $report->renderMarkdown();
        $this->assertTrue(str_contains($markdown, '# 🎯 EidCloud API Contract & Boundary Fuzzing Report'));

        $json = $report->toJson();
        $decoded = json_decode($json, true);
        $this->assertEquals(2, $decoded['summary']['totalTests']);
    }

    public function testEndToEndContractTesterFlow(): void
    {
        $tester = new ContractTester('http://localhost:8080', 2, true);
        $tester->loadSpecFromFile($this->fixtureFile);

        $testCases = $tester->generateTestCases();
        $this->assertTrue(count($testCases) >= 20, 'Should generate robust suite of test cases');

        $report = $tester->run();
        $this->assertTrue($report->getComplianceScore() >= 95.0);
    }
}
