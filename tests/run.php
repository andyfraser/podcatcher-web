<?php

// ─── Mock Environment ────────────────────────────────────────────────────────

$tmpDir = sys_get_temp_dir() . '/podcatcher-tests-' . uniqid();
mkdir($tmpDir, 0777, true);
define('DATA_DIR',     $tmpDir);
define('FEEDS_FILE',   DATA_DIR . '/feeds.json');
define('EPISODES_DIR', DATA_DIR . '/episodes');
define('USER_AGENT',   'Podcatcher-Test/1.0');

// Cleanup on exit
register_shutdown_function(function() use ($tmpDir) {
    if (PHP_OS_FAMILY === 'Windows') {
        exec("rd /s /q " . escapeshellarg($tmpDir));
    } else {
        exec("rm -rf " . escapeshellarg($tmpDir));
    }
});

// ─── Include Libraries ────────────────────────────────────────────────────────

$fetch_mock = null;
function fetch_url(string $url, int $timeout = 15): array {
    global $fetch_mock;
    if ($fetch_mock) return $fetch_mock($url, $timeout);
    return ['ok' => false, 'error' => 'Mock not set'];
}

require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/rss.php';
require_once __DIR__ . '/../lib/actions.php';
require_once __DIR__ . '/../lib/downloader.php';

// Mock session
@session_start();
$_SESSION['csrf_token'] = 'test-token';

require_once __DIR__ . '/TestCase.php';

use Tests\AssertionFailedException;

$testDir = __DIR__ . '/Unit';
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testDir));
$testFiles = new RegexIterator($files, '/Test\.php$/');

$passed = 0;
$failed = 0;
$assertions = 0;
$failures = [];

echo "Running Podcatcher Unit Tests...\n\n";

foreach ($testFiles as $file) {
    if ($file->isDir()) continue;
    
    $existingClasses = get_declared_classes();
    require_once $file->getPathname();
    $newClasses = array_diff(get_declared_classes(), $existingClasses);
    
    foreach ($newClasses as $testClass) {
        $reflection = new ReflectionClass($testClass);
        if (!$reflection->isAbstract() && $reflection->isSubclassOf('Tests\TestCase')) {
            $instance = $reflection->newInstance();
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
            
            foreach ($methods as $method) {
                if (strpos($method->name, 'test') === 0) {
                    try {
                        if ($reflection->hasMethod('setUp')) {
                            $instance->setUp();
                        }
                        
                        $instance->{$method->name}();
                        echo ".";
                        $passed++;
                    } catch (AssertionFailedException $e) {
                        echo "F";
                        $failed++;
                        $failures[] = [
                            'class' => $testClass,
                            'method' => $method->name,
                            'message' => $e->getMessage(),
                            'file' => $file->getPathname(),
                            'line' => $e->getLine()
                        ];
                    } catch (Exception $e) {
                        echo "E";
                        $failed++;
                        $failures[] = [
                            'class' => $testClass,
                            'method' => $method->name,
                            'message' => "Unhandled Exception: " . $e->getMessage(),
                            'file' => $file->getPathname(),
                            'line' => $e->getLine()
                        ];
                    } catch (Error $e) {
                        echo "E";
                        $failed++;
                        $failures[] = [
                            'class' => $testClass,
                            'method' => $method->name,
                            'message' => "Error: " . $e->getMessage(),
                            'file' => $file->getPathname(),
                            'line' => $e->getLine()
                        ];
                    }
                }
            }
            $assertions += $instance->getAssertionCount();
        }
    }
}

echo "\n\n";

if ($failed > 0) {
    echo "Failures:\n";
    foreach ($failures as $i => $failure) {
        echo ($i + 1) . ") {$failure['class']}::{$failure['method']}\n";
        echo "{$failure['message']}\n";
        echo "{$failure['file']}:{$failure['line']}\n\n";
    }
}

echo "Tests: " . ($passed + $failed) . ", Assertions: $assertions, Failures: $failed\n";

exit($failed > 0 ? 1 : 0);
