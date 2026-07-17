<?php

declare(strict_types=1);

namespace Nateq\Sdk\Exceptions;

/** The request body failed validation (400/422), or would have. */
class ValidationException extends NateqException
{
}
