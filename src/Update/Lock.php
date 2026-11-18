<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Update;

interface Lock
{
    public function acquire(): bool;

    public function release(): void;
}
