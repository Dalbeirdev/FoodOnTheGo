<?php

namespace App\Events\Support;

use App\Models\SupportCase;

/**
 * Something happened to a support case (Module 37). Raised AFTER the change has been committed; possibly announced
 * twice, so consumers tolerate a repeat. It carries references and codes — never message text.
 */
final class SupportCaseChanged
{
    public const CREATED = 'created';

    public const ASSIGNED = 'assigned';

    public const STATUS = 'status_changed';

    public const RESOLVED = 'resolved';

    public const CLOSED = 'closed';

    public const REOPENED = 'reopened';

    public const CANCELLED = 'cancelled';

    public const ESCALATED = 'escalated';

    public const PRIORITY = 'priority_changed';

    public readonly int $caseKey;

    public readonly string $caseId;

    public readonly string $caseNumber;

    public readonly string $status;

    public readonly string $requesterType;

    public readonly string $category;

    public readonly int $version;

    /**
     * @param  array<string, mixed>  $facts
     */
    public function __construct(public readonly string $name, SupportCase $case, public readonly array $facts = [])
    {
        $this->caseKey = (int) $case->getKey();
        $this->caseId = (string) $case->public_id;
        $this->caseNumber = (string) $case->case_number;
        $this->status = $case->status->value;
        $this->requesterType = $case->requester_type->value;
        $this->category = (string) $case->category;
        $this->version = (int) $case->version;
    }
}
