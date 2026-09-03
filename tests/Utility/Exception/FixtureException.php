<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Utility\Exception;

use RuntimeException;

/**
 * What a fixture throws to stand for "something outside the library failed".
 *
 * @internal
 */
final class FixtureException extends RuntimeException {}
