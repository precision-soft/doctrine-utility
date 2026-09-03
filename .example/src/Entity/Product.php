<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Example\Entity;

use DateTime;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use PrecisionSoft\Doctrine\Utility\Entity\CreatedTrait;
use PrecisionSoft\Doctrine\Utility\Entity\ModifiedTrait;
use PrecisionSoft\Doctrine\Utility\Repository\DoctrineRepository;
use Symfony\Component\Uid\Uuid;

/**
 * `HasLifecycleCallbacks` is what lets `ModifiedTrait` stamp `modified` on every update.
 */
#[ORM\Entity(repositoryClass: DoctrineRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'product')]
#[ORM\Index(columns: ['barcode'], name: 'idx_product_barcode')]
class Product
{
    use CreatedTrait;
    use ModifiedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    /* the public identity: a uuid, stored as BINARY(16) on the MySQL family and as a native uuid on PostgreSQL */
    #[ORM\Column(type: 'uuid', unique: true)]
    protected Uuid $identity;

    #[ORM\Column(type: 'string', length: 128)]
    protected string $name;

    #[ORM\Column(type: 'string', length: 13)]
    protected string $barcode;

    /* minor units, so a price is an integer and every comparison operator applies */
    #[ORM\Column(type: 'integer')]
    protected int $price;

    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(name: 'currency_id', referencedColumnName: 'id', nullable: false)]
    protected Currency $currency;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: false)]
    protected Category $category;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $attributes = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?DateTime $discontinuedAt = null;

    public function __construct(
        Uuid $identity,
        string $name,
        string $barcode,
        int $price,
        Currency $currency,
        Category $category,
    ) {
        $this->identity = $identity;
        $this->name = $name;
        $this->barcode = $barcode;
        $this->price = $price;
        $this->currency = $currency;
        $this->category = $category;

        $now = new DateTime('now', new DateTimeZone('UTC'));
        $this->setCreated($now);
        $this->setModified($now);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdentity(): Uuid
    {
        return $this->identity;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getBarcode(): string
    {
        return $this->barcode;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function setPrice(int $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    /** @return array<string, mixed>|null */
    public function getAttributes(): ?array
    {
        return $this->attributes;
    }

    /** @param array<string, mixed>|null $attributes */
    public function setAttributes(?array $attributes): static
    {
        $this->attributes = $attributes;

        return $this;
    }

    public function getDiscontinuedAt(): ?DateTime
    {
        return $this->discontinuedAt;
    }

    public function setDiscontinuedAt(?DateTime $discontinuedAt): static
    {
        $this->discontinuedAt = $discontinuedAt;

        return $this;
    }
}
