<?php

namespace Modules\Procurement\Exceptions;

use RuntimeException;

/**
 * The bid-weight configuration is unusable and must not reach a tabulation.
 *
 * A RuntimeException, not a LogicException: this is thrown at BOOT (from
 * ProcurementServiceProvider), not caught by an approve controller. A weighted
 * tabulation is a signed procurement record — an installation whose five aspect
 * weights do not sum to 100 would rank vendors on a scale nobody agreed to, so
 * the misconfiguration stops the application at start rather than silently
 * scoring a bid. The message names the sum it found so an operator can fix
 * config/erp.php procurement.bid_weights.
 */
class BidWeightConfigException extends RuntimeException {}
