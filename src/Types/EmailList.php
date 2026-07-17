<?php

declare(strict_types=1);

namespace Nateq\Sdk\Types;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A page of outbound emails.
 *
 * Iterable, so `foreach ($nateq->emails()->list() as $email)` works directly.
 * `count()` is the size of this page; {@see EmailList::$total} is the size of
 * the whole result set.
 *
 * @implements IteratorAggregate<int, OutboundEmail>
 */
final class EmailList implements Countable, IteratorAggregate
{
    /**
     * @param list<OutboundEmail> $emails
     */
    public function __construct(
        public readonly array $emails,
        public readonly int $total,
        public readonly int $limit,
        public readonly int $offset,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rows = is_array($data['emails'] ?? null) ? $data['emails'] : [];

        $emails = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $emails[] = OutboundEmail::fromArray($row);
            }
        }

        return new self(
            emails: $emails,
            total: Decode::int($data['total'] ?? null),
            limit: Decode::int($data['limit'] ?? null),
            offset: Decode::int($data['offset'] ?? null),
        );
    }

    /** True when more rows exist beyond this page. */
    public function hasMore(): bool
    {
        return $this->offset + count($this->emails) < $this->total;
    }

    /** Offset to pass to the next `list()` call, or null on the last page. */
    public function nextOffset(): ?int
    {
        return $this->hasMore() ? $this->offset + count($this->emails) : null;
    }

    public function count(): int
    {
        return count($this->emails);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->emails);
    }
}
