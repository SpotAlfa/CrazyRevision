<?php /** @noinspection PhpUndefinedMethodInspection */

namespace SpotAlfa\Axessors\CrazyRevision\Tests;

require __DIR__ . '/src.php';

use SpotAlfa\Axessors\CrazyRevision\CrazyRevision;
use SpotAlfa\Axessors\CrazyRevision\CrazyStartup;

class Point
{
    private $x, $y; //> float +get -set -> `$var *= 2`

    public function __construct(float $x, float $y)
    {
        $this->setX($x);
        $this->setY($y);
    }
}

$timestamp = microtime(true);
CrazyStartup::run();
echo (microtime(true) - $timestamp);
