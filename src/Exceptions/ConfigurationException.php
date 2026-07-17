<?php

declare(strict_types=1);

namespace Nateq\Sdk\Exceptions;

/** Bad SDK usage — thrown before any network call (never carries a status). */
class ConfigurationException extends NateqException
{
}
