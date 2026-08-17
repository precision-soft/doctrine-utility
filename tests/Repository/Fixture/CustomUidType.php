<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture;

use Symfony\Bridge\Doctrine\Types\AbstractUidType;
use Symfony\Component\Uid\Uuid;

/**
 * Declares no `getName()`, valid under DBAL 4: rejecting a value then raises `Error`, not `ConversionException`.
 */
class CustomUidType extends AbstractUidType
{
    public const NAME = 'test_custom_uid';

    protected function getUidClass(): string
    {
        return Uuid::class;
    }
}
