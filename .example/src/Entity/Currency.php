<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Example\Entity;

use Doctrine\ORM\Mapping as ORM;
use PrecisionSoft\Doctrine\Utility\Repository\DoctrineRepository;

#[ORM\Entity(repositoryClass: DoctrineRepository::class)]
#[ORM\Table(name: 'currency')]
class Currency
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 3, unique: true)]
    protected string $code;

    #[ORM\Column(type: 'string', length: 64)]
    protected string $name;

    public function __construct(string $code, string $name)
    {
        $this->code = $code;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
