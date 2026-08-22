<?php

namespace Modules\Projects\Exceptions;

use LogicException;

/**
 * Tutup proyek refused because items are still open.
 *
 * Extends LogicException for the same reason BastPrerequisiteException does:
 * the close controller answers any LogicException as a 422 carrying the message
 * verbatim, so the refusal reaches the operator in Indonesian, naming the PO
 * and defect codes that block it. failures() carries the same thing
 * structurally for the checklist panel that draws it.
 */
class ProjectClosureException extends LogicException
{
    /**
     * @param  array<int, array<string, mixed>>  $failures  the checks that did not pass
     */
    public function __construct(string $message, private readonly array $failures = [])
    {
        parent::__construct($message);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
