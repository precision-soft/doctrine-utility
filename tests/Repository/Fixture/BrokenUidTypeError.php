<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture;

use Error;

/**
 * An `Error`, not an exception: the library treats an `Error` out of a uid type as the type's own bug.
 *
 * @internal
 */
final class BrokenUidTypeError extends Error {}
