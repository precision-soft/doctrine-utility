<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;

/**
 * Binds BINARY and throws on anything but a dashed uuid instead of returning null — ramsey/uuid-doctrine's shape.
 */
class StrictBinaryUuidType extends Type
{
    public const NAME = 'test_strict_binary_uuid';

    protected const UUID_PATTERN = '#^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#';

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

        if (1 !== \preg_match(static::UUID_PATTERN, (string)$value)) {
            throw InvalidType::new($value, static::NAME, ['null', 'uuid string']);
        }

        $binary = \hex2bin(\str_replace('-', '', (string)$value));

        return false === $binary ? null : $binary;
    }
}
