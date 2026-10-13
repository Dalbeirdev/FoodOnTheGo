<?php

namespace App\Events\Support;

use App\Models\SupportCase;
use App\Models\SupportCaseMessage;

/**
 * A message was added to the conversation of a support case (Module 37) — by the requester, by support or by the
 * system. Raised AFTER the commit. Internal notes raise nothing: they are not messages. No text travels with it.
 */
final class SupportCaseMessageCreated
{
    public readonly int $caseKey;

    public readonly string $caseId;

    public readonly string $caseNumber;

    public readonly int $messageKey;

    public readonly string $messageId;

    public readonly string $authorType;

    public readonly string $requesterType;

    public readonly bool $first;

    public function __construct(SupportCase $case, SupportCaseMessage $message, bool $first = false)
    {
        $this->caseKey = (int) $case->getKey();
        $this->caseId = (string) $case->public_id;
        $this->caseNumber = (string) $case->case_number;
        $this->messageKey = (int) $message->getKey();
        $this->messageId = (string) $message->public_id;
        $this->authorType = (string) $message->author_type;
        $this->requesterType = $case->requester_type->value;
        $this->first = $first;
    }
}
