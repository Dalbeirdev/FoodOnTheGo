<?php

namespace App\Listeners;

use App\Enums\SupportRequesterType;
use App\Events\Support\SupportCaseChanged;
use App\Events\Support\SupportCaseMessageCreated;
use App\Models\Customer;
use App\Models\SupportCase;
use App\Models\SupportCaseMessage;
use App\Services\Notifications\NotificationOrchestrator;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Support\SupportMetrics;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells a CUSTOMER about their own support case through the Module 33 notification domain (Module 37): that it was
 * received, that support wrote to them, that it was resolved. One notice per case and kind (a reply: per message).
 *
 * Only customers have a notification inbox. A restaurant sees an "unread" mark on its case in the dashboard, and
 * administrators have no notification channel at all — nothing is sent to either from here. An internal note raises
 * no event and can therefore never reach anybody outside the service desk.
 */
final class NotifyOnSupportCase
{
    public const SUBJECT = 'SUPPORT_CASE';

    public function __construct(private readonly NotificationRegistry $registry, private readonly NotificationOrchestrator $orchestrator, private readonly SupportMetrics $metrics) {}

    public function handleCaseChanged(SupportCaseChanged $event): void
    {
        if ($event->requesterType !== SupportRequesterType::Customer->value) {
            return;
        }
        match ($event->name) {
            SupportCaseChanged::CREATED => $this->send('support.case_created', $event->caseKey, 'created'),
            SupportCaseChanged::RESOLVED => $this->send('support.case_resolved', $event->caseKey, 'resolved:v'.$event->version),
            default => null,
        };
    }

    public function handleMessage(SupportCaseMessageCreated $event): void
    {
        // What the customer wrote themselves is not news to them.
        if ($event->requesterType !== SupportRequesterType::Customer->value || ! in_array($event->authorType, [SupportCaseMessage::AUTHOR_SUPPORT, SupportCaseMessage::AUTHOR_SYSTEM], true)) {
            return;
        }
        $this->send('support.reply', $event->caseKey, 'message:'.$event->messageId);
    }

    private function send(string $typeKey, int $caseKey, string $dedupe): void
    {
        $type = $this->registry->get($typeKey);
        if ($type === null) {
            return;
        }
        try {
            $case = SupportCase::query()->with('customer.market')->find($caseKey);
            $customer = $case?->customer;
            if ($case === null || ! $customer instanceof Customer || $case->requester_type !== SupportRequesterType::Customer) {
                return;
            }
            $sent = $this->orchestrator->notify(
                $type,
                $customer,
                ['type' => self::SUBJECT, 'public_id' => (string) $case->public_id, 'reference' => (string) $case->case_number],
                ['case' => (string) $case->case_number],
                'support:'.$case->public_id.':'.$type->key.':'.$dedupe,
            );
            if ($sent !== null) {
                $this->metrics->record('notification_sent');
            }
        } catch (Throwable $e) {
            Log::error('support.notification_failed', ['case' => $caseKey, 'type' => $typeKey, 'class' => $e::class]);
            if (app()->environment('testing')) {
                throw $e; // a test must see a notification bug; production only logs it
            }
        }
    }
}
