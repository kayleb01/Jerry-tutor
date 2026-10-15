<?php

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * A gateway event we can't tie to a subscription yet (often it arrived before the one that creates it).
 */
class UnmatchedGatewayEvent extends RuntimeException {}
