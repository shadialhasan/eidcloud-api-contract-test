<?php

declare(strict_types=1);

namespace EidCloud\ApiContractTest\Reporter;

use EidCloud\ApiContractTest\Runner\TestResult;

class ContractReport
{
    /** @var array<int, TestResult> */
    private array $results;
    private float $durationSeconds;
    private string $targetUrl;
    private string $specTitle;

    /**
     * @param array<int, TestResult> $results
     */
    public function __construct(
        array $results,
        float $durationSeconds = 0.0,
        string $targetUrl = '',
        string $specTitle = ''
    ) {
        $this->results = $results;
        $this->durationSeconds = $durationSeconds;
        $this->targetUrl = $targetUrl;
        $this->specTitle = $specTitle;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getTotalTests(): int
    {
        return count($this->results);
    }

    public function getPassedTests(): int
    {
        return count(array_filter($this->results, fn(TestResult $r) => $r->isPassed()));
    }

    public function getBreachTests(): int
    {
        return count(array_filter($this->results, fn(TestResult $r) => $r->isBreach()));
    }

    public function getComplianceScore(): float
    {
        $total = $this->getTotalTests();
        if ($total === 0) {
            return 100.0;
        }
        return round(($this->getPassedTests() / $total) * 100, 1);
    }

    public function getBreachBreakdown(): array
    {
        $counts = [
            TestResult::BREACH_CONTRACT_BYPASS => 0,
            TestResult::BREACH_SERVER_CRASH => 0,
            TestResult::BREACH_BASELINE_FAILURE => 0,
        ];

        foreach ($this->results as $r) {
            if ($r->isBreach() && $r->getBreachType() !== null) {
                $counts[$r->getBreachType()] = ($counts[$r->getBreachType()] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function getCategoryBreakdown(): array
    {
        $breakdown = [];
        foreach ($this->results as $r) {
            $cat = $r->getTestCase()->getMutationType()->label();
            if (!isset($breakdown[$cat])) {
                $breakdown[$cat] = ['total' => 0, 'passed' => 0, 'breaches' => 0];
            }
            $breakdown[$cat]['total']++;
            if ($r->isPassed()) {
                $breakdown[$cat]['passed']++;
            } else {
                $breakdown[$cat]['breaches']++;
            }
        }
        return $breakdown;
    }

    public function getAverageLatencyMs(): float
    {
        if (empty($this->results)) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($this->results as $r) {
            $total += $r->getHttpResult()->getLatencyMs();
        }
        return round($total / count($this->results), 2);
    }

    public function toArray(): array
    {
        $casesData = [];
        foreach ($this->results as $r) {
            $casesData[] = $r->toArray();
        }

        return [
            'summary' => [
                'specTitle' => $this->specTitle,
                'targetUrl' => $this->targetUrl,
                'totalTests' => $this->getTotalTests(),
                'passed' => $this->getPassedTests(),
                'breaches' => $this->getBreachTests(),
                'complianceScore' => $this->getComplianceScore(),
                'durationSeconds' => round($this->durationSeconds, 2),
                'averageLatencyMs' => $this->getAverageLatencyMs(),
                'breachBreakdown' => $this->getBreachBreakdown(),
                'categoryBreakdown' => $this->getCategoryBreakdown(),
            ],
            'results' => $casesData,
        ];
    }

    public function toJson(bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return (string) json_encode($this->toArray(), $flags);
    }

    public function saveToFile(string $filePath): void
    {
        file_put_contents($filePath, $this->toJson(true));
    }

    public function renderCliTable(): string
    {
        $out = [];
        $out[] = "================================================================================";
        $out[] = "               EIDCLOUD API CONTRACT & BOUNDARY FUZZING REPORT                  ";
        $out[] = "================================================================================";
        if ($this->specTitle) {
            $out[] = "Specification : " . $this->specTitle;
        }
        if ($this->targetUrl) {
            $out[] = "Target URL    : " . $this->targetUrl;
        }
        $out[] = "Execution Time: " . round($this->durationSeconds, 2) . "s  |  Avg Latency: " . $this->getAverageLatencyMs() . "ms";
        $out[] = "--------------------------------------------------------------------------------";
        $out[] = sprintf(
            "COMPLIANCE SCORE: %5.1f%%  [%d / %d Tests Passed]",
            $this->getComplianceScore(),
            $this->getPassedTests(),
            $this->getTotalTests()
        );

        $breaches = $this->getBreachBreakdown();
        $out[] = sprintf(
            "BREACHES: %d  (Contract Bypasses: %d | Server Crashes: %d | Baseline Fails: %d)",
            $this->getBreachTests(),
            $breaches[TestResult::BREACH_CONTRACT_BYPASS] ?? 0,
            $breaches[TestResult::BREACH_SERVER_CRASH] ?? 0,
            $breaches[TestResult::BREACH_BASELINE_FAILURE] ?? 0
        );
        $out[] = "--------------------------------------------------------------------------------";
        $out[] = sprintf("%-32s | %6s | %6s | %8s | %7s", "MUTATION CATEGORY", "TOTAL", "PASS", "BREACHES", "SCORE");
        $out[] = "---------------------------------+--------+--------+----------+---------";

        foreach ($this->getCategoryBreakdown() as $category => $stats) {
            $pct = $stats['total'] > 0 ? round(($stats['passed'] / $stats['total']) * 100, 1) : 100.0;
            $out[] = sprintf(
                "%-32s | %6d | %6d | %8d | %6.1f%%",
                substr($category, 0, 32),
                $stats['total'],
                $stats['passed'],
                $stats['breaches'],
                $pct
            );
        }

        $out[] = "================================================================================";

        if ($this->getBreachTests() > 0) {
            $out[] = "";
            $out[] = "[!] DETECTED CONTRACT BREACHES:";
            $out[] = "--------------------------------------------------------------------------------";
            foreach ($this->results as $r) {
                if ($r->isBreach()) {
                    $tc = $r->getTestCase();
                    $out[] = sprintf(
                        "* [%s] %s %s -> HTTP %d (Expected %s)",
                        $r->getBreachType(),
                        $tc->getMethod(),
                        $tc->getPath(),
                        $r->getHttpResult()->getStatusCode(),
                        $tc->getExpectedStatusCategory()
                    );
                    $out[] = "  Desc   : " . $tc->getDescription();
                    $out[] = "  Message: " . $r->getMessage();
                    $out[] = "";
                }
            }
        }

        return implode(PHP_EOL, $out) . PHP_EOL;
    }

    public function renderMarkdown(): string
    {
        $out = [];
        $out[] = "# 🎯 EidCloud API Contract & Boundary Fuzzing Report";
        $out[] = "";
        $out[] = "- **Specification**: " . ($this->specTitle ?: 'OpenAPI Spec');
        $out[] = "- **Target**: `" . ($this->targetUrl ?: 'N/A') . "`";
        $out[] = "- **Compliance Score**: **" . $this->getComplianceScore() . "%**";
        $out[] = "- **Total Tests Executed**: " . $this->getTotalTests();
        $out[] = "- **Compliant**: " . $this->getPassedTests();
        $out[] = "- **Breaches**: " . $this->getBreachTests();
        $out[] = "- **Duration**: " . round($this->durationSeconds, 2) . "s";
        $out[] = "";
        $out[] = "## Category Scorecard";
        $out[] = "";
        $out[] = "| Mutation Category | Total | Passed | Breaches | Compliance |";
        $out[] = "| :--- | :---: | :---: | :---: | :---: |";

        foreach ($this->getCategoryBreakdown() as $category => $stats) {
            $pct = $stats['total'] > 0 ? round(($stats['passed'] / $stats['total']) * 100, 1) : 100.0;
            $out[] = sprintf(
                "| %s | %d | %d | %d | %.1f%% |",
                $category,
                $stats['total'],
                $stats['passed'],
                $stats['breaches'],
                $pct
            );
        }

        $out[] = "";

        if ($this->getBreachTests() > 0) {
            $out[] = "## ⚠️ Contract Breaches";
            $out[] = "";
            $out[] = "| Type | Method | Path | Status | Expected | Details |";
            $out[] = "| :--- | :---: | :--- | :---: | :---: | :--- |";

            foreach ($this->results as $r) {
                if ($r->isBreach()) {
                    $tc = $r->getTestCase();
                    $out[] = sprintf(
                        "| `%s` | `%s` | `%s` | `%d` | `%s` | %s |",
                        $r->getBreachType(),
                        $tc->getMethod(),
                        $tc->getPath(),
                        $r->getHttpResult()->getStatusCode(),
                        $tc->getExpectedStatusCategory(),
                        str_replace('|', '/', $r->getMessage())
                    );
                }
            }
            $out[] = "";
        }

        return implode(PHP_EOL, $out) . PHP_EOL;
    }
}
