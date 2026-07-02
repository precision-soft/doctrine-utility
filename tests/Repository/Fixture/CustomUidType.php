<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture;

use Symfony\Bridge\Doctrine\Types\AbstractUidType;
use Symfony\Component\Uid\Uuid;

/**
 * Test-only consumer-style uid type: extends AbstractUidType implementing only getUidClass(), deliberately
 * WITHOUT getName() — valid under DBAL 4, where Type no longer declares the method (only Symfony's final
 * UuidType/UlidType re-declare it). The bridge's rejection helpers call $this->getName(), so rejecting a
 * value raises Error instead of ConversionException; used to prove the uid branch still falls back untyped.
 */
class CustomUidType extends AbstractUidType
{
    public const NAME = 'test_custom_uid';

    protected function getUidClass(): string
    {
        return Uuid::class;
    }
}
