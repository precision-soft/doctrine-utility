<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Repository;

use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<object> */
class DoctrineRepository extends EntityRepository
{
    public function hasField(string $fieldName): bool
    {
        return true === $this->getClassMetadata()->hasField($fieldName)
            || (
                true === $this->getClassMetadata()->hasAssociation($fieldName)
                && false === $this->getClassMetadata()->isAssociationInverseSide($fieldName)
            );
    }
}
