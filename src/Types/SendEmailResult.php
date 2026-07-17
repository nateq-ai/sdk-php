<?php

declare(strict_types=1);

namespace Nateq\Sdk\Types;

/** The API's acknowledgement of a send. Delivery itself happens afterwards. */
final class SendEmailResult
{
    /**
     * @param EmailStatus|string $status The enum for known statuses; the raw string
     *   for any the API adds later, so a new status can never crash the SDK.
     * @param array<string, mixed> $raw The response as received.
     */
    public function __construct(
        public readonly string $id,
        public readonly EmailStatus|string $status,
        public readonly string $createdAt,
        public readonly ?string $providerMessageId,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            status: Decode::status($data['status'] ?? null),
            createdAt: (string) ($data['createdAt'] ?? ''),
            providerMessageId: Decode::nullableString($data['providerMessageId'] ?? null),
            raw: $data,
        );
    }
}
