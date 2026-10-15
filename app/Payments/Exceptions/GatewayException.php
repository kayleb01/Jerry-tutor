<?php

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * The provider couldn't be reached or answered with something unexpected. The message is safe to show.
 */
class GatewayException extends RuntimeException {}
