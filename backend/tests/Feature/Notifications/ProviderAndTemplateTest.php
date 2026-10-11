<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationChannel;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\NotificationTemplates;
use App\Services\Notifications\Providers\FcmHttpV1PushProvider;
use App\Services\Notifications\Providers\PushMessage;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The FCM HTTP v1 client against the documented error codes (never a network call: Guzzle's mock handler) and the
 * template layer's escaping, allow-list, fallback and bounds.
 */
class ProviderAndTemplateTest extends TestCase
{
    private function message(string $token = 'device-token-1'): PushMessage
    {
        return new PushMessage($token, 'Your order is ready', 'Order FOTG-1 is ready.', ['type' => 'order.ready', 'target' => 'ORDER_TRACKING', 'reference' => 'FOTG-1'], 'HIGH', 'pickup', 'subject-abc', 3600, 'delivery-1');
    }

    public function test_without_credentials_the_fcm_driver_is_unavailable_and_says_so_instead_of_pretending(): void
    {
        config(['notifications.fcm.credentials' => '', 'notifications.fcm.project_id' => '']);
        $provider = new FcmHttpV1PushProvider;
        $this->assertFalse($provider->configured());
        $health = $provider->health();
        $this->assertSame('UNAVAILABLE', $health['status']);
        $this->assertStringContainsString('PENDING FIREBASE CONFIGURATION', $health['detail']);
        $result = $provider->send($this->message());
        $this->assertFalse($result->accepted);
        $this->assertSame(['AUTH_CONFIGURATION', 'NOT_CONFIGURED'], [$result->errorCategory->value, $result->providerCode]);
    }

    public function test_fcm_error_codes_map_to_the_documented_handling(): void
    {
        $provider = new FcmHttpV1PushProvider;
        $fcm = fn (string $code, int $status, string $googleStatus, string $message = ''): array => ['error' => ['code' => $status, 'status' => $googleStatus, 'message' => $message, 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => $code]]]];

        $this->assertSame('INVALID_DESTINATION', $provider->mapError(404, $fcm('UNREGISTERED', 404, 'NOT_FOUND'))->errorCategory->value);
        $this->assertSame('INVALID_DESTINATION', $provider->mapError(403, $fcm('SENDER_ID_MISMATCH', 403, 'PERMISSION_DENIED'))->errorCategory->value);
        $this->assertSame('INVALID_DESTINATION', $provider->mapError(400, $fcm('INVALID_ARGUMENT', 400, 'INVALID_ARGUMENT', 'The registration token is not a valid FCM registration token'))->errorCategory->value);
        $this->assertSame('PERMANENT', $provider->mapError(400, $fcm('INVALID_ARGUMENT', 400, 'INVALID_ARGUMENT', 'Invalid JSON payload received.'))->errorCategory->value);
        $quota = $provider->mapError(429, $fcm('QUOTA_EXCEEDED', 429, 'RESOURCE_EXHAUSTED'), '120');
        $this->assertSame(['RATE_LIMITED', 120], [$quota->errorCategory->value, $quota->retryAfterSeconds]);
        $this->assertSame(60, $provider->mapError(429, $fcm('QUOTA_EXCEEDED', 429, 'RESOURCE_EXHAUSTED'))->retryAfterSeconds, 'at least a minute');
        $this->assertSame('TRANSIENT', $provider->mapError(503, $fcm('UNAVAILABLE', 503, 'UNAVAILABLE'), '7')->errorCategory->value);
        $this->assertSame(7, $provider->mapError(503, $fcm('UNAVAILABLE', 503, 'UNAVAILABLE'), '7')->retryAfterSeconds);
        $this->assertSame('TRANSIENT', $provider->mapError(500, $fcm('INTERNAL', 500, 'INTERNAL'))->errorCategory->value);
        $this->assertSame('AUTH_CONFIGURATION', $provider->mapError(401, ['error' => ['status' => 'UNAUTHENTICATED', 'message' => 'Request had invalid authentication credentials.']])->errorCategory->value);
        $this->assertSame('AUTH_CONFIGURATION', $provider->mapError(403, ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'The caller does not have permission']])->errorCategory->value);
        $this->assertSame('UNKNOWN', $provider->mapError(418, [])->errorCategory->value);
        $this->assertSame('HTTP_418', $provider->mapError(418, [])->providerCode);
    }

    public function test_a_send_through_the_mocked_http_v1_endpoint_is_accepted_with_the_message_name_and_the_body_has_the_documented_shape(): void
    {
        $dir = sys_get_temp_dir();
        $file = $dir.'/fotg-test-sa-'.getmypid().'.json';
        file_put_contents($file, json_encode(['type' => 'service_account', 'project_id' => 'fotg-test', 'client_email' => 'x@fotg-test.iam.gserviceaccount.com', 'private_key' => 'not-a-key']));
        config(['notifications.fcm.credentials' => $file, 'notifications.fcm.project_id' => 'fotg-test']);
        Cache::put('notifications:fcm:access-token', 'cached-test-access-token', 60);

        $history = [];
        $mock = new MockHandler([new Response(200, [], json_encode(['name' => 'projects/fotg-test/messages/0:1234'])), new Response(404, [], json_encode(['error' => ['status' => 'NOT_FOUND', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]]))]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $provider = new FcmHttpV1PushProvider(new Client(['handler' => $stack]));
        $this->assertTrue($provider->configured());

        $ok = $provider->send($this->message());
        $this->assertTrue($ok->accepted);
        $this->assertSame('projects/fotg-test/messages/0:1234', $ok->providerReference);
        $request = $history[0]['request'];
        $this->assertSame('https://fcm.googleapis.com/v1/projects/fotg-test/messages:send', (string) $request->getUri());
        $this->assertSame('Bearer cached-test-access-token', $request->getHeaderLine('Authorization'));
        $body = json_decode((string) $request->getBody(), true)['message'];
        $this->assertSame('device-token-1', $body['token']);
        $this->assertSame(['HIGH', '3600s', 'subject-abc'], [$body['android']['priority'], $body['android']['ttl'], $body['android']['collapse_key']]);
        $this->assertSame(['channel_id' => 'pickup', 'tag' => 'subject-abc', 'visibility' => 'PRIVATE'], $body['android']['notification']);
        $this->assertSame(['title' => 'Your order is ready', 'body' => 'Order FOTG-1 is ready.'], $body['notification']);
        foreach ($body['data'] as $v) {
            $this->assertIsString($v, 'FCM data values are strings');
        }

        $dead = $provider->send($this->message('device-token-dead'));
        $this->assertSame('INVALID_DESTINATION', $dead->errorCategory->value);
        @unlink($file);
    }

    public function test_an_oversized_payload_is_refused_before_the_provider_is_called(): void
    {
        $dir = sys_get_temp_dir();
        $file = $dir.'/fotg-test-sa2-'.getmypid().'.json';
        file_put_contents($file, json_encode(['type' => 'service_account', 'project_id' => 'fotg-test']));
        config(['notifications.fcm.credentials' => $file, 'notifications.fcm.project_id' => 'fotg-test']);
        $provider = new FcmHttpV1PushProvider(new Client(['handler' => HandlerStack::create(new MockHandler([]))]));
        $big = new PushMessage('t', str_repeat('A', 60), str_repeat('B', 5000), [], 'NORMAL', 'orders', 'c', 60, 'd');
        $result = $provider->send($big);
        $this->assertSame(['PERMANENT', 'MESSAGE_TOO_BIG'], [$result->errorCategory->value, $result->providerCode]);
        @unlink($file);
    }

    public function test_templates_escape_untrusted_text_per_output_bound_lengths_fall_back_to_the_market_locale_and_know_their_version(): void
    {
        $templates = app(NotificationTemplates::class);
        $vars = ['restaurant' => "Burger <b>Hub</b> & Co\x00\x1F", 'order' => 'FOTG-20261009-ABC123', 'pickup_time' => '', 'refund_line' => 'ignored here', 'phone' => '+919876543210'];

        $push = $templates->render('order.ready', NotificationChannel::Push, 'hi-IN', $vars);
        $this->assertSame('en-IN', $push['locale'], 'no Hindi templates: the market default answers');
        $this->assertSame('Your order is ready', $push['title']);
        $this->assertStringContainsString('Burger <b>Hub</b> & Co', $push['body'], 'plain text stays plain (the OS renders it as text)');
        $this->assertStringNotContainsString("\x00", $push['body']);
        $this->assertStringNotContainsString('9876', $push['body'], 'an unknown variable is never substituted');
        $this->assertLessThanOrEqual((int) config('notifications.limits.push_body'), mb_strlen($push['body']));
        $this->assertSame(1, $push['version']);

        $email = $templates->render('order.created', NotificationChannel::Email, 'en-IN', $vars);
        $this->assertStringContainsString('Burger &lt;b&gt;Hub&lt;/b&gt; &amp; Co', $email['html'], 'HTML-escaped in the e-mail');
        $this->assertStringContainsString('Burger <b>Hub</b> & Co', $email['body'], 'the text part is plain');
        $this->assertSame('Your FoodOnTheGo order FOTG-20261009-ABC123', $email['subject']);

        $sms = $templates->render('order.ready', NotificationChannel::Sms, null, $vars);
        $this->assertLessThanOrEqual(160, mb_strlen($sms['body']));
        $this->assertStringStartsWith('FoodOnTheGo:', $sms['body']);

        $accepted = $templates->render('order.accepted', NotificationChannel::InApp, 'en-IN', ['restaurant' => 'X', 'order' => 'Y', 'pickup_time' => '']);
        $this->assertStringContainsString('Pickup as planned', $accepted['body'], 'an empty pickup time falls back to the fragment');
        $this->assertNull($templates->render('order.preparing', NotificationChannel::Sms, 'en-IN', $vars), 'a channel without a template renders nothing');
        $this->assertNull($templates->render('no.such.type', NotificationChannel::Push, 'en-IN', $vars));

        $long = $templates->render('order.ready', NotificationChannel::Push, 'en-IN', ['restaurant' => str_repeat('R', 300), 'order' => 'FOTG-1']);
        $this->assertLessThanOrEqual((int) config('notifications.limits.push_body'), mb_strlen($long['body']));
        $this->assertStringEndsWith('…', $long['body']);
    }

    public function test_every_registry_type_has_an_in_app_template_and_the_registry_describes_itself(): void
    {
        $registry = app(NotificationRegistry::class);
        $templates = app(NotificationTemplates::class);
        foreach ($registry->all() as $type) {
            $this->assertNotNull($templates->render($type->key, NotificationChannel::InApp, 'en-IN', ['restaurant' => 'R', 'order' => 'O']), $type->key.' needs an in-app template');
            foreach ($type->channels() as $channel) {
                if ($channel !== NotificationChannel::InApp) {
                    $this->assertNotNull($templates->render($type->key, $channel, 'en-IN', ['restaurant' => 'R', 'order' => 'O']), $type->key.' lists '.$channel->value.' but has no template for it');
                }
            }
        }
        $described = collect($registry->describe())->keyBy('type');
        $this->assertSame(['order.ready'], $described['order.ready']['events']);
        $this->assertSame('mandatory', $described['account.phone_changed']['channels']['PUSH']);
        $this->assertSame('off', $described['order.preparing']['channels']['PUSH']);
        $this->assertSame(['en-IN'], $described['order.ready']['locales']);
        $this->assertNull($registry->forOrderEvent('order.status_changed'), 'the generic status event notifies nobody (the specific ones do)');
        $this->assertNull($registry->forOrderEvent('order.pickup_credential_reissued'));
    }
}
