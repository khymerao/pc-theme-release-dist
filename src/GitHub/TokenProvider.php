<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\GitHub;

interface TokenProvider
{
    /** The GitHub token, decrypted on demand, or null when none is usable. */
    public function token(): ?string;
}
