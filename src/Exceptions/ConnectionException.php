<?php

declare(strict_types=1);

namespace Nateq\Sdk\Exceptions;

/** The request never produced a response: DNS, TLS, socket, or offline. */
class ConnectionException extends NateqException
{
}
