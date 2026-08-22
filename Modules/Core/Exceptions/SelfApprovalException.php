<?php

namespace Modules\Core\Exceptions;

use LogicException;

/**
 * Refused because the approver is the person who submitted the document.
 *
 * It extends LogicException on purpose: every one of the thirteen approve
 * controllers already wraps its service call in `catch (LogicException)` and
 * answers with ApiController::error() — a 422 carrying the message verbatim —
 * so maker-checker reaches the operator's screen in Indonesian without a single
 * controller being touched.
 *
 * A distinct class only so a caller that genuinely has to tell a segregation
 * refusal apart from a wrong-status refusal can do it by type instead of by
 * matching on the message text.
 */
class SelfApprovalException extends LogicException {}
