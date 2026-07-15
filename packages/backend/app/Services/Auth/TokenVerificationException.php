<?php

declare(strict_types=1);

namespace App\Services\Auth;

use RuntimeException;

/** The presented provider token was missing, malformed, expired, or failed validation. */
final class TokenVerificationException extends RuntimeException {}
