<?php

declare(strict_types=1);

namespace Clearcut\Video\Exceptions;

use RuntimeException;

/**
 * Base for every failure this client raises.
 *
 * Catching this catches everything; the two subclasses below separate the one
 * distinction that changes what a caller should DO — whether retrying later
 * could work.
 */
class ClearcutException extends RuntimeException
{
}
