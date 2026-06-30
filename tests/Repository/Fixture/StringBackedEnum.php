<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture;

/**
 * Test-only string-backed enum, used to assert that an array `IN` filter binds the scalar backing values
 * with ArrayParameterType::STRING instead of letting the enum instances reach the driver.
 */
enum StringBackedEnum: string
{
    case Alpha = 'alpha';
    case Beta = 'beta';
}
