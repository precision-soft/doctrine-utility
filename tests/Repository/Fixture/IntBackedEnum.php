<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture;

/**
 * Test-only int-backed enum, used to assert that an array `IN` filter binds the scalar backing values
 * with ArrayParameterType::INTEGER instead of letting the enum instances reach the driver.
 */
enum IntBackedEnum: int
{
    case First = 1;
    case Second = 2;
}
