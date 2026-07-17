<?php

declare(strict_types=1);

namespace Nateq\Sdk\Exceptions;

/**
 * The key is valid but not allowed to do this (403).
 *
 * Common causes: the key lacks the `emails:send` scope, the endpoint is not
 * exposed to API keys, or the caller's IP is outside the key's allow-list.
 */
class PermissionException extends NateqException
{
}
