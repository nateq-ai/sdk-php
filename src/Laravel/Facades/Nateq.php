<?php

declare(strict_types=1);

namespace Nateq\Sdk\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Nateq\Sdk\Nateq as NateqClient;

/**
 * Facade for the Nateq client.
 *
 * ```php
 * use Nateq\Sdk\Laravel\Facades\Nateq;
 *
 * Nateq::emails()->send(
 *     toEmails: ['customer@example.com'],
 *     subject: 'Welcome aboard',
 *     htmlBody: '<p>Thanks for signing up.</p>',
 * );
 * ```
 *
 * @method static \Nateq\Sdk\Resources\Emails emails()
 * @method static string apiKeyLast4()
 *
 * @see NateqClient
 */
final class Nateq extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return NateqClient::class;
    }
}
