<?php

class TestCase
{
    public function a(): void
    {
        if (true) {
            $this->c();
        }
    }

    public function b(): void {
        $this->c();
    }

    private function c(): void {}
}

$test = new TestCase();

$time = microtime(true);
for ($i = 0; $i < 1e3; $i++) {
    $test->a();
}
echo (microtime(true) - $time) . PHP_EOL;

$time = microtime(true);
for ($i = 0; $i < 1e3; $i++) {
    $test->b();
}
echo (microtime(true) - $time) . PHP_EOL;
