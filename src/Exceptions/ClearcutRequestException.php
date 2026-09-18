<?php

declare(strict_types=1);

namespace Clearcut\Video\Exceptions;

use Throwable;

/**
 * The service was reached and rejected the request.
 *
 * NOT worth retrying unchanged: a 422 means the payload was wrong, a 404 that
 * the job or proposal is gone. Retrying either just fails again, and a queue
 * that retries a permanent failure burns its attempts before anyone looks.
 */
class ClearcutRequestException extends ClearcutException
{
    /**
     * @param  array<string, mixed>  $body  the service's decoded response
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly array $body = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * Whether the request was rejected for being malformed or invalid.
     */
    public function isValidationError(): bool
    {
        return $this->status === 422;
    }

    /**
     * Whether the thing being addressed does not exist.
     *
     * For a proposal this usually means it expired rather than never existed —
     * the service keeps them for a week, and a review started before that has
     * to be re-analysed.
     */
    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /**
     * Whether the service refused because of the resource's state.
     *
     * Either a job that already finished, or an apply step blocked because
     * regions are still undecided — in the second case, deciding them and
     * retrying is exactly what should happen.
     */
    public function isConflict(): bool
    {
        return $this->status === 409;
    }
}
