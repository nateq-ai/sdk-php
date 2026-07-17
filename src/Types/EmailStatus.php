<?php

declare(strict_types=1);

namespace Nateq\Sdk\Types;

/** Delivery status of an outbound email. */
enum EmailStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Opened = 'opened';
    case Clicked = 'clicked';

    /** True once the email has reached the recipient's mail server. */
    public function isDelivered(): bool
    {
        return in_array($this, [self::Delivered, self::Opened, self::Clicked], true);
    }

    /** True when the email will not be delivered and will not be retried. */
    public function isFailure(): bool
    {
        return in_array($this, [self::Bounced, self::Failed, self::Rejected], true);
    }

    /** True while the outcome is still unknown. */
    public function isPending(): bool
    {
        return in_array($this, [self::Pending, self::Sending, self::Sent], true);
    }
}
