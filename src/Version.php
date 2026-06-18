<?php

declare(strict_types=1);

namespace ObfusX;

final class Version
{
    public const VERSION = '0.1.0';

    public static function current(): string
    {
        return self::VERSION;
    }
}
