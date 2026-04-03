<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Function;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Function\AbstractJsonSearch;

/**
 * @internal
 */
final class AbstractJsonSearchTest extends TestCase
{
    public function testModeOneConstant(): void
    {
        static::assertSame('one', AbstractJsonSearch::MODE_ONE);
    }

    public function testModeAllConstant(): void
    {
        static::assertSame('all', AbstractJsonSearch::MODE_ALL);
    }
}
