<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a LINE LIFF id-token cannot be verified server-side: the LINE
 * verify request failed, or the returned payload did not match the expected
 * issuer / audience (channel id).
 *
 * Caught by the member login controller and surfaced as an HTTP 422 so the
 * Vue LIFF page can present a clean "could not sign in with LINE" message
 * without leaking the underlying verification detail.
 *
 * @see \App\Services\Line\LiffVerifyService
 */
final class LineAuthException extends RuntimeException
{
}
