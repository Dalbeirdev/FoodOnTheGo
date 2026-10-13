<?php

namespace App\Enums;

/**
 * Where a support case stands (Module 37). A case only COORDINATES work: none of these states says anything about the
 * order, the payment, the refund or the settlement the case is about.
 *
 *   OPEN ─► ASSIGNED ─► IN_PROGRESS ─┬─► WAITING_FOR_CUSTOMER ─┐
 *     │         │            │       ├─► WAITING_FOR_RESTAURANT ┤
 *     │         │            │       ├─► WAITING_FOR_PROVIDER   ├─► IN_PROGRESS (the awaited answer came, or support resumes)
 *     │         │            │       ├─► WAITING_FOR_FINANCE    │
 *     │         │            │       └─► WAITING_FOR_INTERNAL  ─┘
 *     └─────────┴────────────┴──► RESOLVED ─► CLOSED
 *                                     │          │
 *                                     └──────────┴──► REOPENED ─► (as ASSIGNED / IN_PROGRESS / WAITING / RESOLVED)
 *   any state that is not RESOLVED / CLOSED ──► CANCELLED (withdrawn, duplicate or invalid — final)
 *
 * The status moves only through SupportCaseStateMachine; no request carries a status.
 */
enum SupportCaseStatus: string
{
    case Open = 'OPEN';
    case Assigned = 'ASSIGNED';
    case InProgress = 'IN_PROGRESS';
    case WaitingForCustomer = 'WAITING_FOR_CUSTOMER';
    case WaitingForRestaurant = 'WAITING_FOR_RESTAURANT';
    case WaitingForProvider = 'WAITING_FOR_PROVIDER';
    case WaitingForFinance = 'WAITING_FOR_FINANCE';
    case WaitingForInternal = 'WAITING_FOR_INTERNAL';
    /** Support believes the matter is settled. The requester may still reopen it inside the configured window. */
    case Resolved = 'RESOLVED';
    case Closed = 'CLOSED';
    case Reopened = 'REOPENED';
    case Cancelled = 'CANCELLED';

    private const WAITING = ['WAITING_FOR_CUSTOMER', 'WAITING_FOR_RESTAURANT', 'WAITING_FOR_PROVIDER', 'WAITING_FOR_FINANCE', 'WAITING_FOR_INTERNAL'];

    private const ALLOWED = [
        'OPEN' => ['ASSIGNED', 'IN_PROGRESS', ...self::WAITING, 'RESOLVED', 'CANCELLED'],
        'ASSIGNED' => ['OPEN', 'IN_PROGRESS', ...self::WAITING, 'RESOLVED', 'CANCELLED'],
        'IN_PROGRESS' => ['OPEN', 'ASSIGNED', ...self::WAITING, 'RESOLVED', 'CANCELLED'],
        'WAITING_FOR_CUSTOMER' => ['OPEN', 'ASSIGNED', 'IN_PROGRESS', ...self::WAITING, 'RESOLVED', 'CANCELLED'],
        'WAITING_FOR_RESTAURANT' => ['OPEN', 'ASSIGNED', 'IN_PROGRESS', ...self::WAITING, 'RESOLVED', 'CANCELLED'],
        'WAITING_FOR_PROVIDER' => ['OPEN', 'ASSIGNED', 'IN_PROGRESS', ...self::WAITING, 'RESOLVED', 'CANCELLED'],
        'WAITING_FOR_FINANCE' => ['OPEN', 'ASSIGNED', 'IN_PROGRESS', ...self::WAITING, 'RESOLVED', 'CANCELLED'],
        'WAITING_FOR_INTERNAL' => ['OPEN', 'ASSIGNED', 'IN_PROGRESS', ...self::WAITING, 'RESOLVED', 'CANCELLED'],
        'RESOLVED' => ['CLOSED', 'REOPENED'],
        'CLOSED' => ['REOPENED'],
        'REOPENED' => ['ASSIGNED', 'IN_PROGRESS', ...self::WAITING, 'RESOLVED', 'CANCELLED'],
        'CANCELLED' => [],
    ];

    public function allows(self $to): bool
    {
        return $to !== $this && in_array($to->value, self::ALLOWED[$this->value], true);
    }

    /** Somebody still has something to do: the case is in a queue. */
    public function isActive(): bool
    {
        return ! in_array($this, [self::Resolved, self::Closed, self::Cancelled], true);
    }

    public function isWaiting(): bool
    {
        return in_array($this->value, self::WAITING, true);
    }

    /** Over for good: nothing but a reopening (of CLOSED, by policy) follows. */
    public function isFinal(): bool
    {
        return $this === self::Closed || $this === self::Cancelled;
    }

    /** The states in which the requester may write another message. */
    public function acceptsRequesterMessage(): bool
    {
        return $this->isActive();
    }

    /**
     * What a customer or a restaurant reads. The internal waiting states say who support is waiting for inside the
     * platform — the requester is told only whether the next step is theirs.
     */
    public function forRequester(SupportRequesterType $requester): string
    {
        return match (true) {
            $this === self::WaitingForCustomer && $requester === SupportRequesterType::Customer,
            $this === self::WaitingForRestaurant && $requester === SupportRequesterType::Restaurant => 'WAITING_FOR_YOU',
            $this === self::Resolved => 'RESOLVED',
            $this === self::Closed => 'CLOSED',
            $this === self::Cancelled => 'CANCELLED',
            $this === self::Open, $this === self::Reopened => 'OPEN',
            default => 'IN_PROGRESS',
        };
    }

    public const REQUESTER_WORDS = ['OPEN', 'IN_PROGRESS', 'WAITING_FOR_YOU', 'RESOLVED', 'CLOSED', 'CANCELLED'];

    /**
     * @return list<self>
     */
    public static function waiting(): array
    {
        return array_map(fn (string $v): self => self::from($v), self::WAITING);
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(fn (self $s): string => $s->value, array_filter(self::cases(), fn (self $s): bool => $s->isActive())));
    }
}
