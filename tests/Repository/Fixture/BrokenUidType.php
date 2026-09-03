<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Symfony\Bridge\Doctrine\Types\AbstractUidType;
use Symfony\Component\Uid\Uuid;

/**
 * Declares `getName()` and still raises `Error`, which can only mean a bug in the type's own overrides.
 */
class BrokenUidType extends AbstractUidType
{
    public const NAME = 'test_broken_uid';

    public function getName(): string
    {
        return static::NAME;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        throw new BrokenUidTypeError('broken uid type conversion');
    }

    protected function getUidClass(): string
    {
        return Uuid::class;
    }
}
