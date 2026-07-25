<?php

namespace App\Service;

/**
 * Thrown when an oversized image cannot be downscaled. The message is
 * user-facing (German) and safe to display verbatim.
 */
class ImageDownscaleException extends \RuntimeException
{
}
