<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Entity;

use DateTime;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Entity\CreatedTrait;

/**
 * @internal
 */
final class CreatedTraitTest extends TestCase
{
    /** @phpstan-var object */
    private object $entity;

    protected function setUp(): void
    {
        $this->entity = new class {
            use CreatedTrait;
        };
    }

    public function testGetCreatedDefaultIsNull(): void
    {
        static::assertNull($this->entity->getCreated());
    }

    public function testSetCreatedAndGetCreated(): void
    {
        $dateTime = new DateTime('2024-01-15 10:30:00');
        $returnValue = $this->entity->setCreated($dateTime);

        static::assertSame($dateTime, $this->entity->getCreated());
        static::assertSame($this->entity, $returnValue);
    }

    public function testSetCreatedReturnsSelf(): void
    {
        $dateTime = new DateTime();
        $returnValue = $this->entity->setCreated($dateTime);

        static::assertInstanceOf($this->entity::class, $returnValue);
    }

    public function testSetCreatedOverwritesPreviousValue(): void
    {
        $firstDateTime = new DateTime('2024-01-01');
        $secondDateTime = new DateTime('2024-06-15');

        $this->entity->setCreated($firstDateTime);
        static::assertSame($firstDateTime, $this->entity->getCreated());

        $this->entity->setCreated($secondDateTime);
        static::assertSame($secondDateTime, $this->entity->getCreated());
    }

    public function testGetCreatedReturnType(): void
    {
        static::assertNull($this->entity->getCreated());

        $this->entity->setCreated(new DateTime());
        static::assertInstanceOf(DateTime::class, $this->entity->getCreated());
    }
}
