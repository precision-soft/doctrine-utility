<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Utility;

use PrecisionSoft\Doctrine\Utility\Entity\ModifiedTrait;

/**
 * Named rather than anonymous: an anonymous class is typed `object`, so the trait's calls stop being checked.
 *
 * @internal
 */
final class ModifiedTraitUser
{
    use ModifiedTrait;
}
