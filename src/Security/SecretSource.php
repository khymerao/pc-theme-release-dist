<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Security;

enum SecretSource: string
{
    case Constant = 'constant';
    case InvalidConstant = 'invalid_constant';
    case Database = 'database';
    case Undecryptable = 'undecryptable';
    case None = 'none';
}
