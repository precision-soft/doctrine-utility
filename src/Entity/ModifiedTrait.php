<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

trait ModifiedTrait
{
    #[ORM\Column(type: 'datetime', nullable: false, options: ['default' => 'CURRENT_TIMESTAMP', 'update' => true])]
    private DateTime $modified;

    public function getModified(): ?DateTime
    {
        return $this->modified;
    }

    public function setModified(DateTime $modified): self
    {
        $this->modified = $modified;

        return $this;
    }
}
