<?php

namespace Modules\Projects\Exceptions;

use LogicException;

/**
 * BAST II refused because its prerequisites are not met.
 *
 * It extends LogicException on purpose, following SelfApprovalException: every
 * approve controller in the house already wraps its service call in
 * `catch (LogicException)` and answers with ApiController::error() — a 422
 * carrying the message verbatim — so the refusal reaches the operator in
 * Indonesian without a single one of them being touched.
 *
 * BECAUSE THE SPA IS OFF-LIMITS HERE, THE MESSAGE *IS* THE USER INTERFACE. There
 * is no checklist panel to render, so the message names every failing item in
 * one sentence, including the codes of the defects that block it. failures()
 * carries the same thing structurally for a caller that wants to draw it.
 */
class BastPrerequisiteException extends LogicException
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
