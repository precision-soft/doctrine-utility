<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Utility\Entity;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use PrecisionSoft\Doctrine\Utility\Repository\DoctrineRepository;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\BinaryUuidType;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\IntBackedEnum;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\StringBackedEnum;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

/**
 * @internal
 */
#[ORM\Entity(repositoryClass: DoctrineRepository::class)]
#[ORM\Table(name: 'filter_subject')]
#[ORM\Index(columns: ['label'], name: 'idx_filter_subject_label')]
class FilterSubject
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    protected string $label = '';

    /* the one uid column that never holds null, so a keyset may sort on it */
    #[ORM\Column(type: 'uuid')]
    protected Uuid $identity;

    #[ORM\Column(type: BinaryUuidType::NAME, nullable: true)]
    protected ?string $binaryUuid = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    protected ?Uuid $uuid = null;

    #[ORM\Column(type: 'ulid', nullable: true)]
    protected ?Ulid $ulid = null;

    #[ORM\Column(type: 'date', nullable: true)]
    protected ?DateTime $dateValue = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?DateTime $dateTimeValue = null;

    #[ORM\Column(type: 'datetimetz', nullable: true)]
    protected ?DateTime $dateTimeTzValue = null;

    #[ORM\Column(type: 'time', nullable: true)]
    protected ?DateTime $timeValue = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    protected ?DateTimeImmutable $dateImmutableValue = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $dateTimeImmutableValue = null;

    #[ORM\Column(type: 'dateinterval', nullable: true)]
    protected ?DateInterval $intervalValue = null;

    #[ORM\Column(type: 'integer', nullable: true, enumType: IntBackedEnum::class)]
    protected ?IntBackedEnum $intBackedEnum = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true, enumType: StringBackedEnum::class)]
    protected ?StringBackedEnum $stringBackedEnum = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $payload = null;

    public function __construct()
    {
        $this->identity = Uuid::v4();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getIdentity(): Uuid
    {
        return $this->identity;
    }

    public function setIdentity(Uuid $identity): static
    {
        $this->identity = $identity;

        return $this;
    }

    public function getBinaryUuid(): ?string
    {
        return $this->binaryUuid;
    }

    public function setBinaryUuid(?string $binaryUuid): static
    {
        $this->binaryUuid = $binaryUuid;

        return $this;
    }

    public function getUuid(): ?Uuid
    {
        return $this->uuid;
    }

    public function setUuid(?Uuid $uuid): static
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getUlid(): ?Ulid
    {
        return $this->ulid;
    }

    public function setUlid(?Ulid $ulid): static
    {
        $this->ulid = $ulid;

        return $this;
    }

    public function getDateValue(): ?DateTime
    {
        return $this->dateValue;
    }

    public function setDateValue(?DateTime $dateValue): static
    {
        $this->dateValue = $dateValue;

        return $this;
    }

    public function getDateTimeValue(): ?DateTime
    {
        return $this->dateTimeValue;
    }

    public function setDateTimeValue(?DateTime $dateTimeValue): static
    {
        $this->dateTimeValue = $dateTimeValue;

        return $this;
    }

    public function getDateTimeTzValue(): ?DateTime
    {
        return $this->dateTimeTzValue;
    }

    public function setDateTimeTzValue(?DateTime $dateTimeTzValue): static
    {
        $this->dateTimeTzValue = $dateTimeTzValue;

        return $this;
    }

    public function getTimeValue(): ?DateTime
    {
        return $this->timeValue;
    }

    public function setTimeValue(?DateTime $timeValue): static
    {
        $this->timeValue = $timeValue;

        return $this;
    }

    public function getDateImmutableValue(): ?DateTimeImmutable
    {
        return $this->dateImmutableValue;
    }

    public function setDateImmutableValue(?DateTimeImmutable $dateImmutableValue): static
    {
        $this->dateImmutableValue = $dateImmutableValue;

        return $this;
    }

    public function getDateTimeImmutableValue(): ?DateTimeImmutable
    {
        return $this->dateTimeImmutableValue;
    }

    public function setDateTimeImmutableValue(?DateTimeImmutable $dateTimeImmutableValue): static
    {
        $this->dateTimeImmutableValue = $dateTimeImmutableValue;

        return $this;
    }

    public function getIntervalValue(): ?DateInterval
    {
        return $this->intervalValue;
    }

    public function setIntervalValue(?DateInterval $intervalValue): static
    {
        $this->intervalValue = $intervalValue;

        return $this;
    }

    public function getIntBackedEnum(): ?IntBackedEnum
    {
        return $this->intBackedEnum;
    }

    public function setIntBackedEnum(?IntBackedEnum $intBackedEnum): static
    {
        $this->intBackedEnum = $intBackedEnum;

        return $this;
    }

    public function getStringBackedEnum(): ?StringBackedEnum
    {
        return $this->stringBackedEnum;
    }

    public function setStringBackedEnum(?StringBackedEnum $stringBackedEnum): static
    {
        $this->stringBackedEnum = $stringBackedEnum;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /** @param array<string, mixed>|null $payload */
    public function setPayload(?array $payload): static
    {
        $this->payload = $payload;

        return $this;
    }
}
