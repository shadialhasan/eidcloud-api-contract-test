<?php

declare(strict_types=1);

// Register zero-dependency autoloader
spl_autoload_register(function (string $class) {
    $srcPrefix = 'EidCloud\\ApiContractTest\\';
    $srcBaseDir = dirname(__DIR__) . '/src/';

    $testsPrefix = 'EidCloud\\ApiContractTest\\Tests\\';
    $testsBaseDir = __DIR__ . '/';

    if (strncmp($testsPrefix, $class, strlen($testsPrefix)) === 0) {
        $rel = substr($class, strlen($testsPrefix));
        $file = $testsBaseDir . str_replace('\\', '/', $rel) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }

    if (strncmp($srcPrefix, $class, strlen($srcPrefix)) === 0) {
        $rel = substr($class, strlen($srcPrefix));
        $file = $srcBaseDir . str_replace('\\', '/', $rel) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

use EidCloud\ApiContractTest\Tests\ApiContractTest;

echo "================================================================================" . PHP_EOL;
echo "           🚀 EidCloud API Contract Test Suite Runner (Pure PHP 8.2+)           " . PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo "PHP Version: " . PHP_VERSION . PHP_EOL;
echo "Executing test cases..." . PHP_EOL . PHP_EOL;

$startTime = microtime(true);
$suite = new ApiContractTest();
$results = $suite->runAll();
$duration = microtime(true) - $startTime;

$total = count($results);
$passed = 0;
$failed = 0;

foreach ($results as $method => $res) {
    if ($res['status'] === 'PASS') {
        $passed++;
        printf("  [PASS] %-50s (OK)\n", $method);
    } else {
        $failed++;
        printf("  [FAIL] %-50s\n", $method);
        echo "         Error: " . $res['error'] . PHP_EOL;
    }
}

echo PHP_EOL . "================================================================================" . PHP_EOL;
printf("SUMMARY: Total: %d | Passed: %d | Failed: %d | Time: %.4fs\n", $total, $passed, $failed, $duration);
echo "================================================================================" . PHP_EOL;

if ($failed > 0) {
    echo "❌ TEST SUITE FAILED (" . $failed . " failures)" . PHP_EOL;
    exit(1);
}

echo "✅ ALL TESTS PASSED SUCCESSFULLY! (100% compliance)" . PHP_EOL;
exit(0);
