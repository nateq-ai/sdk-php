<?php

declare(strict_types=1);

namespace Nateq\Sdk\Types;

/**
 * A stored outbound email, as returned by `get()` and `list()`.
 *
 * The API returns more fields than are modelled here, and adds to them over
 * time. Anything not promoted to a property stays reachable through
 * {@see OutboundEmail::$raw}, so a new field is usable without an SDK upgrade.
 */
final class OutboundEmail
{
    /**
     * @param list<string> $toEmails
     * @param list<string> $ccEmails
     * @param list<string> $bccEmails
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $organizationId,
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly array $toEmails,
        public readonly array $ccEmails,
        public readonly array $bccEmails,
        public readonly ?string $subject,
        public readonly ?string $htmlBody,
        public readonly ?string $plainTextBody,
        public readonly EmailStatus|string $status,
        public readonly ?string $messageId,
        public readonly ?string $providerMessageId,
        public readonly ?string $emailAddressId,
        public readonly ?string $ticketId,
        public readonly ?string $conversationId,
        public readonly ?string $contactId,
        public readonly ?string $sentByUserId,
        public readonly bool $isAutomatic,
        public readonly ?string $deliveredAt,
        public readonly ?string $bouncedAt,
        public readonly ?string $openedAt,
        public readonly int $openCount,
        public readonly ?string $bounceReason,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Decode::string($data['id'] ?? null),
            organizationId: Decode::string($data['organizationId'] ?? null),
            fromEmail: Decode::string($data['fromEmail'] ?? null),
            fromName: Decode::nullableString($data['fromName'] ?? null),
            toEmails: Decode::stringList($data['toEmails'] ?? null),
            ccEmails: Decode::stringList($data['ccEmails'] ?? null),
            bccEmails: Decode::stringList($data['bccEmails'] ?? null),
            subject: Decode::nullableString($data['subject'] ?? null),
            htmlBody: Decode::nullableString($data['htmlBody'] ?? null),
            plainTextBody: Decode::nullableString($data['plainTextBody'] ?? null),
            status: Decode::status($data['status'] ?? null),
            messageId: Decode::nullableString($data['messageId'] ?? null),
            providerMessageId: Decode::nullableString($data['providerMessageId'] ?? null),
            emailAddressId: Decode::nullableString($data['emailAddressId'] ?? null),
            ticketId: Decode::nullableString($data['ticketId'] ?? null),
            conversationId: Decode::nullableString($data['conversationId'] ?? null),
            contactId: Decode::nullableString($data['contactId'] ?? null),
            sentByUserId: Decode::nullableString($data['sentByUserId'] ?? null),
            isAutomatic: Decode::bool($data['isAutomatic'] ?? null),
            deliveredAt: Decode::nullableString($data['deliveredAt'] ?? null),
            bouncedAt: Decode::nullableString($data['bouncedAt'] ?? null),
            openedAt: Decode::nullableString($data['openedAt'] ?? null),
            openCount: Decode::int($data['openCount'] ?? null),
            bounceReason: Decode::nullableString($data['bounceReason'] ?? null),
            createdAt: Decode::string($data['createdAt'] ?? null),
            updatedAt: Decode::string($data['updatedAt'] ?? null),
            raw: $data,
        );
    }
}
