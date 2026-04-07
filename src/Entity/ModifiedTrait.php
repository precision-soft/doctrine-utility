<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Entity;

use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Consuming entity class must have the #[ORM\HasLifecycleCallbacks] attribute for the PreUpdate callback to work.
 */
trait ModifiedTrait
{
    #[ORM\Column(type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?DateTime $modified = null;

    public function getModified(): ?DateTime
    {
        return $this->modified;
    }

    public function setModified(DateTime $modified): static
    {
        $this->modified = $modified;

        return $this;
    }

    #[ORM\PreUpdate]
    public function updateModifiedTimestamp(): void
    {
        $this->modified = new DateTime();
    }
}
