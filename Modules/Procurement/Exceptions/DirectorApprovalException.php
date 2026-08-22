<?php

namespace Modules\Procurement\Exceptions;

use LogicException;

/**
 * Refused because the document's value demands a director-level approver and
 * this approver is not one.
 *
 * It extends LogicException on purpose: the PO and SPK approve controllers
 * already wrap their service call in `catch (LogicException)` and answer with
 * ApiController::error() — a 422 carrying the message verbatim — so the
 * refusal reaches the operator's screen in Indonesian without either
 * controller being touched.
 *
 * A distinct class only so a caller that genuinely has to tell "not a
 * director" apart from "wrong status" or a maker-checker refusal can do it by
 * type instead of by matching on the message text.
 */
class DirectorApprovalException extends LogicException {}
