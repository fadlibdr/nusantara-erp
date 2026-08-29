<?php

namespace Modules\Core\Exceptions;

use LogicException;

/**
 * Refused because an n-level approval ladder was not satisfied by THIS approver.
 *
 * Two shapes reach it: a repeat approver (the same person cannot supply two of
 * the distinct approvals a level needs) and a level-2+ approval offered by
 * someone who does not hold the module's director permission. Both are the
 * ladder saying "not you, not yet" — a wrong-actor refusal, not a wrong-status
 * one.
 *
 * It extends LogicException on purpose, the same reasoning as SelfApproval
 * and DirectorApprovalException: the approve controllers already wrap their
 * service call in `catch (LogicException)` and answer with a 422 carrying the
 * message verbatim, so the refusal reaches the operator's screen in Indonesian
 * without a controller being touched. A distinct class only so a caller can
 * tell a ladder refusal apart from maker-checker by type, not message text.
 */
class ApprovalLevelException extends LogicException {}
