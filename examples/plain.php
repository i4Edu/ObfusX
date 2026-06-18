<?php

declare(strict_types=1);

function greet(string $name): void
{
    echo "Hello {$name}!" . PHP_EOL;
}

greet('ObfusX');
