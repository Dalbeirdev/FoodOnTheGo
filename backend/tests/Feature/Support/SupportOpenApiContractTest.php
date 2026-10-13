<?php

namespace Tests\Feature\Support;

use App\Enums\NotificationActionTarget;
use App\Enums\Permission;
use App\Enums\SupportCasePriority;
use App\Enums\SupportCaseSource;
use App\Enums\SupportCaseStatus;
use App\Enums\SupportEscalationReason;
use App\Enums\SupportLinkType;
use App\Enums\SupportRequesterType;
use App\Enums\SupportResolutionCode;
use App\Enums\SupportTeam;
use App\Models\Refund;
use App\Services\Support\SupportDomainActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\BuildsSupport;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * Module 37 — the support API answers what backend/openapi/openapi.json says it answers, every support route is
 * documented, and no request has room for something the server decides.
 */
class SupportOpenApiContractTest extends TestCase
{
    use BuildsSupport, RefreshDatabase, ValidatesOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_the_answers_of_the_customer_the_restaurant_and_the_service_desk_match_their_schemas(): void
    {
        $this->slaPolicy();
        [, $headers, $order, $payment, $refund] = $this->rejectedPaidOrder('+919876543210');

        // Customer.
        $this->assertMatchesSchema('SupportCustomerCatalog', $this->customerSupport($headers, 'GET', '/catalog')->assertOk()->json());
        $created = $this->customerSupport($headers, 'POST', '/cases', ['category' => 'REFUND', 'subcategory' => 'REFUND_PENDING', 'subject' => 'Where is my refund?', 'message' => 'The order was rejected.', 'order' => $order['order_number'], 'payment' => $payment['id'], 'refund' => $refund->supportReference(), 'source' => 'ANDROID'])->assertCreated()->json();
        $this->assertMatchesSchema('SupportCaseEnvelope', $created);
        $id = $created['case']['id'];
        $this->assertMatchesSchema('SupportCaseRequesterPage', $this->customerSupport($headers, 'GET', '/cases')->assertOk()->json());
        $this->assertMatchesSchema('SupportCaseEnvelope', $this->customerSupport($headers, 'GET', '/cases/'.$id)->assertOk()->json());
        $this->assertMatchesSchema('SupportRequesterMessagePage', $this->customerSupport($headers, 'GET', '/cases/'.$id.'/messages')->assertOk()->json());
        $this->assertMatchesSchema('SupportRequesterReplyEnvelope', $this->customerSupport($headers, 'POST', '/cases/'.$id.'/messages', ['message' => 'Any news?', 'client_message_id' => 'contract-000001'])->assertCreated()->json());
        foreach ([$this->customerSupport($headers, 'GET', '/cases/CS-ZZZZZZZZ')->assertNotFound(), $this->customerSupport($headers, 'POST', '/cases', ['category' => 'ORDER'])->assertStatus(422)] as $error) {
            $this->assertMatchesSchema('Error', $error->json());
        }

        // The service desk — every resource of the case and every action.
        $admin = $this->agent([...self::DESK, Permission::AdminOrdersView, Permission::AdminPaymentsView, Permission::AdminRefundsView, Permission::AdminPaymentsReconcile, Permission::AdminCustomersView, Permission::AdminSystemView]);
        $this->assertMatchesSchema('SupportAdminCatalog', $this->desk('GET', '/catalog')->assertOk()->json());
        $this->assertMatchesSchema('SupportAgents', $this->desk('GET', '/agents')->assertOk()->json());
        $this->assertMatchesSchema('SupportQueues', $this->desk('GET', '/queues')->assertOk()->json());
        $this->assertMatchesSchema('SupportDeskNumbers', $this->desk('GET', '/metrics')->assertOk()->json());
        $this->assertMatchesSchema('SupportAdminCasePage', $this->desk('GET', '/cases?q='.$order['order_number'])->assertOk()->assertJsonCount(1, 'data')->json());
        $path = '/cases/'.$id;
        $v = fn (): int => $this->version($id);
        $this->assertMatchesSchema('SupportAdminCaseEnvelope', $this->desk('GET', $path)->assertOk()->json());
        $this->assertMatchesSchema('SupportAdminMessagePage', $this->desk('GET', $path.'/messages')->assertOk()->json());
        $this->assertMatchesSchema('SupportNoteEnvelope', $this->desk('POST', $path.'/notes', ['note' => 'Refund is REQUIRED.', 'visibility' => 'INTERNAL'])->assertCreated()->json());
        $this->assertMatchesSchema('SupportNotePage', $this->desk('GET', $path.'/notes')->assertOk()->json());
        $this->assertMatchesSchema('SupportAdminReplyEnvelope', $this->desk('POST', $path.'/messages', ['message' => 'We are checking.', 'visibility' => 'EXTERNAL', 'then' => 'WAITING_FOR_CUSTOMER', 'expected_version' => $this->version($id)])->assertCreated()->json());
        $panels = $this->desk('GET', $path.'/links')->assertOk()->json();
        $this->assertMatchesSchema('SupportLinkPanels', $panels);
        $this->assertSame([true, true, true], array_column($panels['links'], 'visible'));
        $this->assertMatchesSchema('SupportLinkPanels', $this->desk('POST', $path.'/links', ['type' => 'CUSTOMER', 'reference' => (string) Refund::query()->sole()->customer_public_id])->assertCreated()->json());
        $paymentLink = collect($panels['links'])->firstWhere('type', 'PAYMENT')['id'];
        $this->assertMatchesSchema('SupportDomainActionResult', $this->desk('POST', $path.'/domain-actions', ['action' => 'PAYMENT_RECONCILE', 'link' => $paymentLink, 'reason' => 'Contract test.'])->assertOk()->json());
        foreach ([['assign', ['admin' => (string) $admin->public_id]], ['priority', ['priority' => 'HIGH', 'reason' => 'Money is owed.']], ['resume', []], ['wait', ['for' => 'FINANCE']], ['start', []],
            ['resolve', ['resolution_code' => 'ANSWERED', 'note' => 'Explained.']], ['close', []], ['reopen', ['reason' => 'The customer wrote again.']]] as [$action, $body]) {
            $this->assertMatchesSchema('SupportAdminCaseEnvelope', $this->desk('POST', $path.'/'.$action, $body + ['expected_version' => $v()])->assertOk()->json(), $action);
        }
        $this->assertMatchesSchema('SupportAdminCaseEnvelope', $this->desk('POST', $path.'/annotations', ['summary' => 'Refund owed, customer informed.', 'next_action_at' => '2026-10-09T09:00:00Z', 'expected_version' => $this->version($id)])->assertOk()->json());
        $this->assertMatchesSchema('SupportAdminEscalated', $this->desk('POST', $path.'/escalate', ['reason' => 'FINANCIAL_RISK', 'level' => 'L2', 'note' => 'Please approve the refund.', 'expected_version' => $v()])->assertOk()->json());
        $escalated = $this->desk('GET', $path)->assertOk()->json();
        $this->assertMatchesSchema('SupportAdminCaseEnvelope', $escalated);
        $this->assertSame(['L2', 'FINANCE', 1, 1], [$escalated['case']['escalation_level'], $escalated['case']['assigned']['team'], count($escalated['case']['escalations']), $escalated['case']['reopen_count']]);
        $this->assertMatchesSchema('SupportEventPage', $this->desk('GET', $path.'/events')->assertOk()->json());
        $this->assertMatchesSchema('SupportAdminCaseEnvelope', $this->desk('POST', $path.'/cancel', ['resolution_code' => 'OTHER', 'note' => 'Handled elsewhere.', 'expected_version' => $v()])->assertOk()->json());
        $internal = $this->desk('POST', '/cases', ['category' => 'TECHNICAL', 'subject' => 'Tablet offline at a counter', 'note' => 'Reported by phone.', 'market' => $this->market->slug, 'links' => [['type' => 'ORDER', 'reference' => $order['order_number']]]])->assertCreated()->json();
        $this->assertMatchesSchema('SupportAdminCaseEnvelope', $internal);
        foreach ([$this->desk('POST', $path.'/start', ['expected_version' => 1])->assertStatus(409), $this->desk('POST', $path.'/messages', ['message' => 'x', 'visibility' => 'INTERNAL'])->assertStatus(422), $this->desk('GET', '/cases/CS-ZZZZZZZZ')->assertNotFound()] as $error) {
            $this->assertMatchesSchema('Error', $error->json());
        }
        $this->actingAsPrincipal($admin);
        $this->assertMatchesSchema('SupportSystemHealth', $this->getJson('/api/v1/admin/system/support')->assertOk()->json());

        // Restaurant.
        $this->asActor($this->member($this->organization, 'OWNER'));
        $this->assertMatchesSchema('SupportRestaurantCatalog', $this->restaurantSupport('GET', '/catalog')->assertOk()->json());
        $restaurant = $this->restaurantSupport('POST', '/cases', ['location' => (string) $this->burgerHub->public_id, 'category' => 'ORDER', 'subject' => 'About a rejected order', 'message' => 'We were out of buns.', 'order' => $order['order_number']])->assertCreated()->json();
        $this->assertMatchesSchema('SupportCaseEnvelope', $restaurant);
        $rid = $restaurant['case']['id'];
        $this->assertMatchesSchema('SupportCaseRequesterPage', $this->restaurantSupport('GET', '/cases?location='.$this->burgerHub->public_id)->assertOk()->json());
        $this->assertMatchesSchema('SupportRequesterReplyEnvelope', $this->restaurantSupport('POST', '/cases/'.$rid.'/messages', ['message' => 'One more detail.'])->assertCreated()->json());
        $this->assertMatchesSchema('SupportRequesterMessagePage', $this->restaurantSupport('GET', '/cases/'.$rid.'/messages')->assertOk()->json());
        $this->assertMatchesSchema('SupportCaseEnvelope', $this->restaurantSupport('POST', '/cases/'.$rid.'/close', ['intent' => 'WITHDRAW'])->assertOk()->json());
        $this->assertMatchesSchema('SupportCaseEnvelope', $this->restaurantSupport('GET', '/cases/'.$rid)->assertOk()->json());
        // Customer close / reopen of the first case's sibling.
        $second = $this->customerSupport($headers, 'POST', '/cases', ['category' => 'OTHER', 'subject' => 'Another', 'message' => 'Question'])->assertCreated()->json('case.id');
        $this->agent();
        $this->desk('POST', '/cases/'.$second.'/resolve', ['resolution_code' => 'ANSWERED', 'note' => 'Answered.', 'expected_version' => 1])->assertOk();
        $this->assertMatchesSchema('SupportCaseEnvelope', $this->customerSupport($headers, 'POST', '/cases/'.$second.'/reopen', ['message' => 'Not yet.'])->assertOk()->json());
        $this->assertMatchesSchema('SupportCaseEnvelope', $this->customerSupport($headers, 'POST', '/cases/'.$second.'/close', ['intent' => 'WITHDRAW'])->assertOk()->json());
    }

    public function test_every_support_route_is_documented_and_no_request_has_room_for_what_the_server_decides(): void
    {
        $doc = $this->openApi();
        $documented = [];
        foreach ($doc['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }
        // Module 38: the analytics reports (/…/analytics/…) are read-only reports ABOUT this domain, not routes of it — they are inventoried by AnalyticsOpenApiContractTest.
        $mine = fn (string $route): bool => ! str_contains($route, '/analytics/') && (bool) preg_match('#/support(/|$)#', $route);
        $registered = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn ($r) => collect($r->methods())->reject(fn (string $m): bool => $m === 'HEAD')->map(fn (string $m): string => $m.' '.preg_replace('#^/api/v1#', '', '/'.ltrim($r->uri(), '/'))))
            ->filter($mine)->unique()->sort()->values()->all();
        $this->assertSame($registered, collect($documented)->filter($mine)->sort()->values()->all(), 'every support route is documented and nothing else is');
        // 43 = 8 customer + 8 restaurant + 26 service desk + the health page.
        $count = fn (string $prefix): int => collect($registered)->filter(fn (string $r): bool => str_contains($r, $prefix))->count();
        $this->assertSame([43, 8, 8, 26, 1], [count($registered), $count(' /customer/support'), $count(' /restaurant/support'), $count(' /admin/support'), $count(' /admin/system/support')]);
        $this->assertSame([], collect($registered)->filter(fn (string $r): bool => preg_match('#^(PUT|PATCH|DELETE) #', $r) === 1)->values()->all(), 'no generic update or removal of a case exists');
        // A requester has a conversation and nothing else: no notes, events, links, assignment or status resource.
        $this->assertSame([], collect($registered)->filter(fn (string $r): bool => preg_match('#/(customer|restaurant)/support/.*(notes|events|links|assign|priority|escalate|resolve|cancel|domain-actions|annotations|metrics|queues|agents)#', $r) === 1)->values()->all());
        // Security: each surface is its own principal's.
        foreach ($doc['paths'] as $path => $operations) {
            if (! $mine($path)) {
                continue;
            }
            $expected = match (true) {
                str_starts_with($path, '/customer/') => 'customer',
                str_starts_with($path, '/restaurant/') => 'restaurant',
                default => 'admin',
            };
            foreach ($operations as $method => $operation) {
                $this->assertSame([[$expected => []]], $operation['security'], $method.' '.$path);
            }
        }

        // No request schema of a requester can carry what the server decides; none of the desk can carry a status,
        // a requester or an author.
        $schemas = $doc['components']['schemas'];
        $never = ['status', 'requester_type', 'requester', 'customer_id', 'customer', 'restaurant_user_id', 'author', 'author_type', 'actor', 'actor_type', 'actor_id', 'case_number', 'version', 'sla', 'sla_status', 'first_response_at', 'resolved_at', 'closed_at', 'system_key', 'classification', 'restricted_class'];
        $requesterOnly = ['priority', 'assigned_team', 'team', 'assigned_admin', 'admin', 'visibility', 'internal', 'note', 'summary', 'resolution_code', 'escalation_level', 'market', 'links', 'dispute'];
        $requests = ['SupportCustomerCaseRequest', 'SupportRestaurantCaseRequest', 'SupportRequesterMessageRequest', 'SupportReopenRequest', 'SupportEmptyRequest', 'SupportAdminCaseRequest', 'SupportReplyRequest', 'SupportNoteRequest', 'SupportAssignRequest',
            'SupportPriorityRequest', 'SupportStepRequest', 'SupportWaitRequest', 'SupportResolveRequest', 'SupportCloseRequest', 'SupportAdminReopenRequest', 'SupportCancelRequest', 'SupportEscalateRequest', 'SupportLinkRequest', 'SupportAnnotateRequest', 'SupportDomainActionRequest'];
        foreach ($requests as $name) {
            $this->assertFalse($schemas[$name]['additionalProperties'], $name.' is closed');
            $this->assertSame([], array_values(array_intersect(array_keys($schemas[$name]['properties']), $never)), $name);
        }
        foreach (['SupportCustomerCaseRequest', 'SupportRestaurantCaseRequest', 'SupportRequesterMessageRequest', 'SupportReopenRequest', 'SupportEmptyRequest'] as $name) {
            $this->assertSame([], array_values(array_intersect(array_keys($schemas[$name]['properties']), $requesterOnly)), $name);
        }
        $this->assertSame([], array_values(array_intersect(array_keys($schemas['SupportCustomerCaseRequest']['properties']), ['settlement', 'payout', 'location', 'organization'])));
        $this->assertSame([], array_values(array_intersect(array_keys($schemas['SupportRestaurantCaseRequest']['properties']), ['payment', 'refund'])));
        // A reply says EXTERNAL and a note says INTERNAL — and nothing else is a value of either.
        $this->assertSame([['EXTERNAL'], ['INTERNAL']], [$schemas['SupportReplyRequest']['properties']['visibility']['enum'], $schemas['SupportNoteRequest']['properties']['visibility']['enum']]);
        // What a requester reads has no place for the desk's own things.
        foreach (['SupportCaseSummary', 'SupportCaseForRequester', 'SupportRequesterMessage'] as $name) {
            $this->assertFalse($schemas[$name]['additionalProperties'], $name);
            $this->assertSame([], array_values(array_intersect(array_keys($schemas[$name]['properties']), ['priority', 'assigned', 'sla', 'resolution', 'summary', 'classification', 'restricted_class', 'escalation_level', 'version', 'requester', 'notes', 'visibility', 'author_type', 'system_key', 'can_notes'])), $name);
        }
        $this->assertSame(['YOU', 'SUPPORT', 'SYSTEM'], $schemas['SupportRequesterMessage']['properties']['author']['enum']);

        // The document's enums are the code's.
        $values = fn (array $cases): array => array_map(fn ($c): string => $c->value, $cases);
        $row = $schemas['SupportAdminCaseRow']['properties'];
        $this->assertSame($values(SupportCaseStatus::cases()), $row['status']['enum']);
        $this->assertSame($values(SupportCasePriority::cases()), $row['priority']['enum']);
        $this->assertSame($values(SupportCaseSource::cases()), $row['source']['enum']);
        $this->assertSame($values(SupportRequesterType::cases()), $row['requester']['properties']['type']['enum']);
        $this->assertSame([...$values(SupportTeam::cases()), null], $row['assigned']['properties']['team']['enum']);
        $this->assertSame(SupportCaseStatus::REQUESTER_WORDS, $schemas['SupportCaseSummary']['properties']['status']['enum']);
        $this->assertSame($values(SupportResolutionCode::cases()), $schemas['SupportResolveRequest']['properties']['resolution_code']['enum']);
        $this->assertSame($values(SupportEscalationReason::cases()), $schemas['SupportEscalateRequest']['properties']['reason']['enum']);
        $this->assertSame($values(SupportLinkType::cases()), $schemas['SupportLinkPanel']['properties']['type']['enum']);
        $this->assertSame(SupportDomainActionService::ACTIONS, $schemas['SupportDomainActionRequest']['properties']['action']['enum']);
        $this->assertSame($values(NotificationActionTarget::cases()), $schemas['NotificationAction']['properties']['target']['enum']);
        // What the document states about the things that are not there.
        $this->assertSame([false], $schemas['SupportAdminCase']['properties']['sla']['properties']['contractual']['enum']);
        $this->assertSame([false], $schemas['SupportSlaPolicy']['properties']['contractual']['enum']);
        foreach (['mark_payment_verified', 'mark_refund_completed', 'edit_settlement', 'edit_payout'] as $never) {
            $this->assertSame(['NOT_PROVIDED'], $schemas['SupportAdminCase']['properties']['domain_authority']['properties'][$never]['enum']);
        }
    }
}
