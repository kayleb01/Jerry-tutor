<?php

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * The plan can't be bought through the chosen gateway right now (not linked, or switched off).
 */
class CheckoutUnavailable extends RuntimeException {}
