<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Security;

/**
 * Where a secret comes from right now, and whether it can be used.
 */
enum SecretSource: string
{
    case Constant = 'constant';
    case InvalidConstant = 'invalid_constant';
    case Database = 'database';
    case Undecryptable = 'undecryptable';
    case None = 'none';

    /** A valid value is available (wp-config constant or decryptable stored value). */
    public function isUsable(): bool
    {
        return self::Constant === $this || self::Database === $this;
    }

    /** Defined in wp-config.php, valid or not: the screen cannot change it. */
    public function isConstant(): bool
    {
        return self::Constant === $this || self::InvalidConstant === $this;
    }
}
