<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture;

enum StringBackedEnum: string
{
    case Alpha = 'alpha';
    case Beta = 'beta';
}
