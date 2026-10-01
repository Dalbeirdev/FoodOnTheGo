<?php

namespace Tests\Feature\Auth;

use App\Enums\PrincipalType;
use App\Models\AdminUser;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Notifications\MfaChangedNotification;
use App\Services\Rbac\RoleService;
use App\Support\Totp;
use Database\Factories\AdminUserFactory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * The account owner is told by e-mail when multi-factor authentication is turned on, turned off or reset by
 * an administrator.
 */
class MfaChangeNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function code(string $secret): string
    {
        return Totp::codeAt($secret, now()->getTimestamp());
    }

    private function superAdmin(): AdminUser
    {
        $this->seed(RoleSeeder::class);
        $admin = AdminUser::factory()->create();
        app(RoleService::class)->assign($admin, Role::query()->where('principal_type', PrincipalType::AdminUser->value)->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $admin;
    }

    private function withMfa(AdminUser $admin): AdminUser
    {
        $admin->forceFill(['mfa_secret' => Totp::generateSecret(), 'mfa_enabled_at' => now(), 'mfa_recovery_codes' => [hash('sha256', 'abcde-fghij')]])->save();

        return $admin;
    }

    public function test_the_owner_is_told_when_they_turn_mfa_on_and_off(): void
    {
        Notification::fake();
        $admin = AdminUser::factory()->create();
        $headers = $this->bearer($admin);

        $secret = $this->postJson('/api/v1/auth/mfa/totp/setup', [], $headers)->assertOk()->json('secret');
        Notification::assertNothingSent();   // nothing changed yet
        $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => '000000'], $headers)->assertUnprocessable();
        Notification::assertNothingSent();
        $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => $this->code($secret)], $headers)->assertOk();
        Notification::assertSentToTimes($admin, MfaChangedNotification::class, 1);
        Notification::assertSentTo($admin, fn (MfaChangedNotification $n): bool => $n->change() === MfaChangedNotification::ENABLED);

        $this->travel(2)->minutes();
        $this->deleteJson('/api/v1/auth/mfa/totp', ['password' => AdminUserFactory::PASSWORD, 'code' => '000000'], $headers)->assertUnprocessable();
        $this->deleteJson('/api/v1/auth/mfa/totp', ['password' => AdminUserFactory::PASSWORD, 'code' => $this->code($secret)], $headers)->assertNoContent();
        Notification::assertSentToTimes($admin, MfaChangedNotification::class, 2);
        Notification::assertSentTo($admin, fn (MfaChangedNotification $n): bool => $n->change() === MfaChangedNotification::DISABLED);
    }

    public function test_restaurant_users_are_told_as_well(): void
    {
        Notification::fake();
        $staff = RestaurantUser::factory()->create();
        $headers = $this->bearer($staff);

        $secret = $this->postJson('/api/v1/auth/mfa/totp/setup', [], $headers)->assertOk()->json('secret');
        $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => $this->code($secret)], $headers)->assertOk();

        Notification::assertSentTo($staff, fn (MfaChangedNotification $n): bool => $n->change() === MfaChangedNotification::ENABLED);
    }

    public function test_a_reset_by_an_administrator_tells_the_owner_and_only_the_owner(): void
    {
        Notification::fake();
        $super = $this->superAdmin();
        $target = $this->withMfa(AdminUser::factory()->create());
        $this->actingAsPrincipal($super);

        $this->postJson("/api/v1/admin/users/{$target->public_id}/mfa/reset", ['reason' => 'x'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/users/{$super->public_id}/mfa/reset", ['reason' => 'My own account'])->assertConflict();
        Notification::assertNothingSent();   // a refused reset tells nobody

        $this->postJson("/api/v1/admin/users/{$target->public_id}/mfa/reset", ['reason' => 'Phone lost'])->assertOk();
        Notification::assertSentToTimes($target, MfaChangedNotification::class, 1);
        Notification::assertSentTo($target, fn (MfaChangedNotification $n): bool => $n->change() === MfaChangedNotification::RESET_BY_ADMINISTRATOR);
        Notification::assertNotSentTo($super, MfaChangedNotification::class);
    }

    public function test_the_message_goes_to_the_account_address_and_contains_no_link_secret_or_reason(): void
    {
        $super = $this->superAdmin();
        $secret = Totp::generateSecret();
        $target = $this->withMfa(AdminUser::factory()->create(['email' => 'lea.dubois@foodonthego.example']));
        $target->forceFill(['mfa_secret' => $secret])->save();
        $this->actingAsPrincipal($super);
        $this->travelTo('2026-10-01 18:45:00');

        $this->postJson("/api/v1/admin/users/{$target->public_id}/mfa/reset", ['reason' => 'Private note for the audit trail'])->assertOk();

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $mail = $messages[0]->getOriginalMessage();
        $this->assertSame(['lea.dubois@foodonthego.example'], array_map(fn ($a) => $a->getAddress(), $mail->getTo()));
        $this->assertSame('An administrator reset multi-factor authentication on your FoodOnTheGo account', $mail->getSubject());
        $body = $mail->getTextBody();
        $this->assertStringContainsString('1 Oct 2026, 18:45 UTC', $body);
        $this->assertStringContainsString('signed out on every device', $body);
        $this->assertStringContainsString('change your password now', $body);
        foreach ([$secret, 'abcde-fghij', 'Private note', 'http://', 'https://', $super->email, $super->name] as $absent) {
            $this->assertStringNotContainsString($absent, $body.$mail->getHtmlBody());
        }
    }

    public function test_the_notice_is_written_in_every_configured_language_that_has_a_translation(): void
    {
        config(['auth_security.notice_locales' => ['en', 'hi', 'xx']]);   // "xx" has no translation and is skipped
        $super = $this->superAdmin();
        $target = $this->withMfa(AdminUser::factory()->create());
        $this->actingAsPrincipal($super);
        $this->travelTo('2026-10-01 18:45:00');

        $this->postJson("/api/v1/admin/users/{$target->public_id}/mfa/reset", ['reason' => 'Phone lost'])->assertOk();

        $mail = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();
        $this->assertSame('An administrator reset multi-factor authentication on your FoodOnTheGo account', $mail->getSubject());
        foreach ([$mail->getTextBody(), $mail->getHtmlBody()] as $body) {
            $this->assertStringContainsString('1 Oct 2026, 18:45 UTC', $body);
            $this->assertStringContainsString('एक एडमिनिस्ट्रेटर ने आपके FoodOnTheGo खाते का मल्टी-फ़ैक्टर ऑथेंटिकेशन रीसेट किया', $body);
            $this->assertStringContainsString('2026, 18:45 UTC को इस खाते का', $body);
            $this->assertStringContainsString('तुरंत अपना पासवर्ड बदलें', $body);
            $this->assertLessThan(strpos($body, 'तुरंत अपना पासवर्ड बदलें'), strpos($body, 'change your password now'), 'English first, then Hindi');
            $this->assertStringNotContainsString('http', $body);
            $this->assertStringNotContainsString('auth.mfa_', $body);
        }
        $this->assertSame(1, substr_count($mail->getHtmlBody(), '<div lang="hi"'));
        $this->assertSame(0, substr_count($mail->getHtmlBody(), 'lang="xx"'));

        // No usable language configured: the fallback language is used, never an empty message.
        config(['auth_security.notice_locales' => ['xx']]);
        $this->assertStringContainsString('turned on for this account', (new MfaChangedNotification(MfaChangedNotification::ENABLED, now()))->toMail($target)->viewData['sections'][0]['lines'][0]);
    }

    public function test_the_hindi_translation_covers_every_notice_text_with_the_same_placeholders(): void
    {
        $en = array_filter(require lang_path('en/auth.php'), fn (string $k): bool => str_starts_with($k, 'mfa_'), ARRAY_FILTER_USE_KEY);
        $hi = require lang_path('hi/auth.php');

        $this->assertCount(10, $en);
        $this->assertSame(array_keys($en), array_keys($hi));
        foreach ($en as $key => $text) {
            $this->assertSame(substr_count($text, ':when'), substr_count($hi[$key], ':when'), $key);
            $this->assertMatchesRegularExpression('/\\p{Devanagari}/u', $hi[$key], $key);
            $this->assertStringNotContainsString('http', $hi[$key]);
        }
    }

    public function test_a_mail_failure_is_reported_and_does_not_undo_the_reset(): void
    {
        Exceptions::fake();
        $super = $this->superAdmin();
        $target = $this->withMfa(AdminUser::factory()->create());
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('mail provider down'));
        $this->actingAsPrincipal($super);

        $this->postJson("/api/v1/admin/users/{$target->public_id}/mfa/reset", ['reason' => 'Phone lost'])->assertOk()->assertJson(['mfa_enabled' => false]);

        $this->assertFalse($target->fresh()->hasMfaEnabled());
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'mail provider down');
    }
}
