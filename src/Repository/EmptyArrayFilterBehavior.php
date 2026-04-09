<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Repository;

enum EmptyArrayFilterBehavior: string
{
    case ThrowException = 'throw_exception';
    case MatchNone = 'match_none';
}
