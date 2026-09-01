<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Binds `ParameterType::BINARY`, which Symfony's own UuidType does not: that is what selects the binary branch.
 */
class BinaryUuidType extends Type
{
    public const NAME = 'test_binary_uuid';

    /** @param array<string, mixed> $column */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return true === $platform instanceof PostgreSQLPlatform ? 'BYTEA' : 'BINARY(16)';
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
