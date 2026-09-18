<?php

declare(strict_types=1);

namespace Clearcut\Video\Exceptions;

/**
 * The service could not be reached at all.
 *
 * Worth retrying later — a restart, a tunnel that dropped, a deploy in
 * progress. A queued job should release its claim and be retried rather than
 * marked permanently failed, because nothing about the request was wrong.
 */
class ClearcutUnavailableException extends ClearcutException
{
}
