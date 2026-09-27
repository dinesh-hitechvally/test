<?php

namespace App\Services\Portfolio;

use RuntimeException;

/** A buy/sell that breaks portfolio rules (e.g. selling more than you hold) — shown to the user as a 422. */
class InvalidTransactionException extends RuntimeException {}
