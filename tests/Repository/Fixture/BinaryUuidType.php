<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Test-only Doctrine type mimicking a uuid stored as BINARY(16): it converts the dashed-string
 * representation to its 16-byte binary form and binds as ParameterType::BINARY. Used to reproduce
 * the array `IN` regression without depending on a real uuid type (none ships with this package).
 */
class BinaryUuidType extends Type
{
    public const NAME = 'test_binary_uuid';

    /** @param array<string, mixed> $column */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'BINARY(16)';
    }

    public function getBindingType(): ParameterType
    {
        return ParameterType::BINARY;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        $binary = \hex2bin(\str_replace('-', '', (string)$value));

        return false === $binary ? null : $binary;
    }
}
