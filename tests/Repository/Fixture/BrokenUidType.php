<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Error;
use Symfony\Bridge\Doctrine\Types\AbstractUidType;
use Symfony\Component\Uid\Uuid;

/**
 * Test-only consumer-style uid type that DOES declare getName() but whose conversion raises Error for every
 * value — simulating a genuine bug in a consumer override (e.g. getUidClass() pointing to a renamed class).
 * Used to prove the uid branch rethrows such Errors instead of silently falling back to the untyped binding.
 */
class BrokenUidType extends AbstractUidType
{
    public const NAME = 'test_broken_uid';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function getUidClass(): string
    {
        return Uuid::class;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        throw new Error('broken uid type conversion');
    }
}
