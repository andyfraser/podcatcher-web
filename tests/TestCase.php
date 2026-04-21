<?php

namespace Tests;

class AssertionFailedException extends \Exception {}

abstract class TestCase {
    protected int $assertionCount = 0;

    public function getAssertionCount(): int {
        return $this->assertionCount;
    }

    protected function assertEquals($expected, $actual, string $message = ''): void {
        $this->assertionCount++;
        if ($expected !== $actual) {
            $msg = $message ?: "Expected " . var_export($expected, true) . ", but got " . var_export($actual, true);
            throw new AssertionFailedException($msg);
        }
    }

    protected function assertTrue($condition, string $message = ''): void {
        $this->assertionCount++;
        if ($condition !== true) {
            $msg = $message ?: "Expected true, but got " . var_export($condition, true);
            throw new AssertionFailedException($msg);
        }
    }

    protected function assertFalse($condition, string $message = ''): void {
        $this->assertionCount++;
        if ($condition !== false) {
            $msg = $message ?: "Expected false, but got " . var_export($condition, true);
            throw new AssertionFailedException($msg);
        }
    }

    protected function assertCount(int $expectedCount, $haystack, string $message = ''): void {
        $this->assertionCount++;
        $actualCount = count($haystack);
        if ($expectedCount !== $actualCount) {
            $msg = $message ?: "Expected count $expectedCount, but got $actualCount";
            throw new AssertionFailedException($msg);
        }
    }

    protected function assertNotNull($actual, string $message = ''): void {
        $this->assertionCount++;
        if ($actual === null) {
            $msg = $message ?: "Expected not null";
            throw new AssertionFailedException($msg);
        }
    }

    protected function assertNotEmpty($actual, string $message = ''): void {
        $this->assertionCount++;
        if (empty($actual)) {
            $msg = $message ?: "Expected not empty";
            throw new AssertionFailedException($msg);
        }
    }

    protected function assertArrayHasKey($key, $array, string $message = ''): void {
        $this->assertionCount++;
        if (!is_array($array) || !array_key_exists($key, $array)) {
            $msg = $message ?: "Expected array to have key '$key'";
            throw new AssertionFailedException($msg);
        }
    }
}
