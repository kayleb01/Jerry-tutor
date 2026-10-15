<?php

namespace App\Payments\Exceptions;

/**
 * The provider timed out or had a server error, so the outcome of the request is unknown:
 * a charge may still have gone through.
 */
class GatewayUnreachable extends GatewayException {}
