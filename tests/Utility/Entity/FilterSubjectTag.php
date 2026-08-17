<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Utility\Entity;

use Doctrine\ORM\Mapping as ORM;
use PrecisionSoft\Doctrine\Utility\Repository\DoctrineRepository;

/**
 * @internal
 */
#[ORM\Entity(repositoryClass: DoctrineRepository::class)]
#[ORM\Table(name: 'filter_subject_tag')]
#[ORM\Index(columns: ['name'], name: 'idx_filter_subject_tag_name')]
class FilterSubjectTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    protected string $name = '';

    #[ORM\ManyToOne(targetEntity: FilterSubject::class)]
    #[ORM\JoinColumn(name: 'filter_subject_id', referencedColumnName: 'id', nullable: false)]
    protected ?FilterSubject $filterSubject = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getFilterSubject(): ?FilterSubject
    {
        return $this->filterSubject;
    }

    public function setFilterSubject(?FilterSubject $filterSubject): static
    {
        $this->filterSubject = $filterSubject;

        return $this;
    }
}
