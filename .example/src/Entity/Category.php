<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Example\Entity;

use Doctrine\ORM\Mapping as ORM;
use PrecisionSoft\Doctrine\Utility\Repository\DoctrineRepository;

#[ORM\Entity(repositoryClass: DoctrineRepository::class)]
#[ORM\Table(name: 'category')]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    protected string $name;

    #[ORM\Column(type: 'integer')]
    protected int $position;

    public function __construct(string $name, int $position)
    {
        $this->name = $name;
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
