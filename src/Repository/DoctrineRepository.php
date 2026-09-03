<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;

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

    public function hasAssociation(string $fieldName): bool
    {
        return true === $this->getClassMetadata()->hasAssociation($fieldName);
    }

    /**
     * Whether the column behind a mapped name may hold null: a field by its mapping, an owning side to-one
     * association by its join columns, which are nullable unless every one of them says otherwise.
     */
    public function allowsNull(string $fieldName): bool
    {
        $classMetadata = $this->getClassMetadata();

        if (true === $classMetadata->hasField($fieldName)) {
            return true === $classMetadata->isNullable($fieldName);
        }

        if (false === $classMetadata->hasAssociation($fieldName)) {
            return false;
        }

        $associationMapping = $classMetadata->getAssociationMapping($fieldName);

        if (false === ($associationMapping instanceof ToOneOwningSideMapping)) {
            return false;
        }

        foreach ($associationMapping->joinColumns as $joinColumnMapping) {
            if (false !== $joinColumnMapping->nullable) {
                return true;
            }
        }

        return false;
    }
}
