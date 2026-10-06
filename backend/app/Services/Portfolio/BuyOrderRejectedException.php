<?php

namespace App\Services\Portfolio;

use RuntimeException;

/** A BUY signal failed one of the checks and no order was created. */
class BuyOrderRejectedException extends RuntimeException {}
