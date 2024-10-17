<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Repository;

use Doctrine\ORM\EntityRepository;

class DoctrineRepository extends EntityRepository
{
    final public function hasField(string $fieldName): bool
    {
        return $this->getClassMetadata()->hasField($fieldName)
            || (true === $this->getClassMetadata()->hasAssociation($fieldName) && false === $this->getClassMetadata()->isAssociationInverseSide($fieldName));
    }
}
