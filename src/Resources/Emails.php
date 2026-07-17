<?php

declare(strict_types=1);

namespace Nateq\Sdk\Resources;

use Nateq\Sdk\Exceptions\ValidationException;
use Nateq\Sdk\Http\Transport;
use Nateq\Sdk\Types\EmailList;
use Nateq\Sdk\Types\EmailStatus;
use Nateq\Sdk\Types\OutboundEmail;
use Nateq\Sdk\Types\SendEmailResult;

/**
 * Outbound email operations. Reachable at `$nateq->emails()`.
 */
final class Emails
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Sends an email, returning once the API has accepted it.
     *
     * Delivery is asynchronous: a successful return means the API took
     * responsibility for the message, not that it landed. Poll {@see get()} or
     * subscribe to a webhook for the outcome.
     *
     * Requires an API key with the `emails:send` scope.
     *
     * A failed send is **not** replayed automatically unless the API rejected it
     * outright (429), because a request that fails after reaching the server may
     * already have sent the mail. On a timeout or 5xx, look the email up with
     * {@see list()} before retrying, or you risk sending twice.
     *
     * ```php
     * $nateq->emails()->send(
     *     toEmails: ['customer@example.com'],
     *     subject: 'Welcome aboard',
     *     htmlBody: '<p>Thanks for signing up.</p>',
     * );
     * ```
     *
     * @param list<string>          $toEmails       One or more recipients. Required.
     * @param string                $subject        Required, 1-998 characters.
     * @param string|null           $htmlBody       HTML body. Provide this, $plainTextBody, or both.
     * @param string|null           $plainTextBody  Plain-text body.
     * @param string|null           $fromEmail      Override the From address. Must be a verified organization address.
     * @param string|null           $emailAddressId Verified organization address to send from. Defaults to the org default.
     * @param list<string>          $ccEmails
     * @param list<string>          $bccEmails
     * @param string|null           $inReplyTo      `Message-ID` this email replies to (threading).
     * @param string|null           $references     `References` header (threading).
     * @param list<string>          $attachmentIds  IDs of previously uploaded files to attach.
     * @param array<string, string> $headers        Extra custom headers.
     *
     * @throws ValidationException if the arguments are unusable, before any call is made
     * @throws \Nateq\Sdk\Exceptions\PermissionException if the key lacks the `emails:send` scope
     */
    public function send(
        array $toEmails,
        string $subject,
        ?string $htmlBody = null,
        ?string $plainTextBody = null,
        ?string $fromEmail = null,
        ?string $fromName = null,
        ?string $emailAddressId = null,
        array $ccEmails = [],
        array $bccEmails = [],
        ?string $replyTo = null,
        ?string $inReplyTo = null,
        ?string $references = null,
        ?string $ticketId = null,
        ?string $conversationId = null,
        ?string $contactId = null,
        array $attachmentIds = [],
        array $headers = [],
    ): SendEmailResult {
        // Validate locally what the API would reject anyway, so the common
        // mistakes surface as a typed error instead of an opaque 400 — and
        // without putting the credential on the wire for a doomed request.
        if ($toEmails === []) {
            throw new ValidationException('`toEmails` must contain at least one recipient.');
        }
        if (trim($subject) === '') {
            throw new ValidationException('`subject` is required.');
        }
        if (trim((string) $htmlBody) === '' && trim((string) $plainTextBody) === '') {
            throw new ValidationException('Provide `htmlBody`, `plainTextBody`, or both.');
        }

        $body = $this->compact([
            'emailAddressId' => $emailAddressId,
            'fromEmail' => $fromEmail,
            'fromName' => $fromName,
            'toEmails' => array_values($toEmails),
            'ccEmails' => $ccEmails === [] ? null : array_values($ccEmails),
            'bccEmails' => $bccEmails === [] ? null : array_values($bccEmails),
            'replyTo' => $replyTo,
            'subject' => $subject,
            'htmlBody' => $htmlBody,
            'plainTextBody' => $plainTextBody,
            'inReplyTo' => $inReplyTo,
            'references' => $references,
            'ticketId' => $ticketId,
            'conversationId' => $conversationId,
            'contactId' => $contactId,
            'attachmentIds' => $attachmentIds === [] ? null : array_values($attachmentIds),
            'headers' => $headers === [] ? null : $headers,
        ]);

        $data = $this->transport->request(
            method: 'POST',
            path: '/outbound-emails',
            body: $body,
            // Sending is not idempotent and the API has no idempotency key, so an
            // ambiguous failure must surface rather than silently double-send.
            retryOnAmbiguousFailure: false,
        );

        return SendEmailResult::fromArray($data);
    }

    /**
     * Fetches a single sent email by id, including delivery status.
     *
     * Requires an API key with the `emails:read` scope.
     *
     * @throws \Nateq\Sdk\Exceptions\NotFoundException if no such email is visible to this key
     */
    public function get(string $id): OutboundEmail
    {
        if (trim($id) === '') {
            throw new ValidationException('`id` is required.');
        }

        $data = $this->transport->request(
            method: 'GET',
            path: '/outbound-emails/'.rawurlencode($id),
            retryOnAmbiguousFailure: true,
        );

        return OutboundEmail::fromArray($data);
    }

    /**
     * Lists sent emails, newest first.
     *
     * Requires an API key with the `emails:read` scope.
     *
     * @param EmailStatus|string|null $status  Filter by delivery status.
     * @param string|null             $toEmail Matches against any recipient.
     * @param int|null                $limit   Defaults to 50 server-side; capped at 100.
     */
    public function list(
        EmailStatus|string|null $status = null,
        ?string $emailAddressId = null,
        ?string $fromEmail = null,
        ?string $toEmail = null,
        ?string $ticketId = null,
        ?string $conversationId = null,
        ?string $contactId = null,
        ?string $sentByUserId = null,
        ?bool $isAutomatic = null,
        ?int $limit = null,
        ?int $offset = null,
    ): EmailList {
        $query = $this->compact([
            'status' => $status instanceof EmailStatus ? $status->value : $status,
            'emailAddressId' => $emailAddressId,
            'fromEmail' => $fromEmail,
            'toEmail' => $toEmail,
            'ticketId' => $ticketId,
            'conversationId' => $conversationId,
            'contactId' => $contactId,
            'sentByUserId' => $sentByUserId,
            'isAutomatic' => $isAutomatic,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $data = $this->transport->request(
            method: 'GET',
            path: '/outbound-emails',
            query: $query,
            retryOnAmbiguousFailure: true,
        );

        return EmailList::fromArray($data);
    }

    /**
     * Drops null entries, so an omitted argument is absent from the payload
     * rather than sent as an explicit null the API would have to interpret.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function compact(array $values): array
    {
        return array_filter($values, static fn ($value) => $value !== null);
    }
}
