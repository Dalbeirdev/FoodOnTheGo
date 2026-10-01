<?php

namespace Tests\Feature\Auth;

use App\Enums\PrincipalType;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Services\Rbac\RoleService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Each restaurant or admin user can choose the language of the e-mails they receive.
 */
class StaffLanguageTest extends TestCase
{
    use RefreshDatabase;

    private const EN = 'Reset your FoodOnTheGo password';

    private const HI = 'अपना FoodOnTheGo पासवर्ड रीसेट करें';

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth_security.notice_locales' => ['en', 'hi']]);
    }

    private function lastMail(): Email
    {
        $messages = app('mailer')->getSymfonyTransport()->messages();

        return $messages[count($messages) - 1]->getOriginalMessage();
    }

    private function resetMailFor(AdminUser $admin): Email
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/admin/password/forgot', ['email' => $admin->email])->assertOk();

        return $this->lastMail();
    }

    public function test_the_session_shows_the_choice_and_the_available_languages(): void
    {
        $admin = AdminUser::factory()->create();
        $headers = $this->bearer($admin);

        $this->getJson('/api/v1/auth/me', $headers)->assertOk()->assertJson(['preferred_locale' => null, 'notice_locales' => ['en', 'hi']]);

        // Only languages that are configured AND translated are offered; never an empty list.
        config(['auth_security.notice_locales' => ['hi', 'xx']]);
        $this->getJson('/api/v1/auth/me', $headers)->assertJson(['notice_locales' => ['hi']]);
        config(['auth_security.notice_locales' => []]);
        $this->getJson('/api/v1/auth/me', $headers)->assertJson(['notice_locales' => ['en']]);
    }

    public function test_a_staff_member_chooses_changes_and_clears_their_language(): void
    {
        $admin = AdminUser::factory()->create();
        $other = AdminUser::factory()->create();
        $headers = $this->bearer($admin);

        $this->putJson('/api/v1/auth/language', ['locale' => 'hi'], $headers)->assertOk()->assertJson(['id' => $admin->public_id, 'preferred_locale' => 'hi']);
        $this->assertSame(['hi', null], [$admin->fresh()->preferred_locale, $other->fresh()->preferred_locale]);
        $this->getJson('/api/v1/auth/me', $headers)->assertJson(['preferred_locale' => 'hi']);

        $this->putJson('/api/v1/auth/language', ['locale' => 'fr'], $headers)->assertUnprocessable();
        $this->putJson('/api/v1/auth/language', ['locale' => 'xx'], $headers)->assertUnprocessable();
        $this->putJson('/api/v1/auth/language', [], $headers)->assertUnprocessable();
        $this->assertSame('hi', $admin->fresh()->preferred_locale);

        $this->putJson('/api/v1/auth/language', ['locale' => null], $headers)->assertOk()->assertJson(['preferred_locale' => null]);
        $this->assertNull($admin->fresh()->preferred_locale);
    }

    public function test_restaurant_users_have_it_too_and_nobody_else_reaches_it(): void
    {
        $staff = RestaurantUser::factory()->create();
        $this->putJson('/api/v1/auth/language', ['locale' => 'hi'], $this->bearer($staff))->assertOk()->assertJson(['preferred_locale' => 'hi']);
        $this->assertSame('hi', $staff->fresh()->preferred_locale);

        $this->app['auth']->forgetGuards();
        $this->putJson('/api/v1/auth/language', ['locale' => 'hi'])->assertUnauthorized();
        $this->putJson('/api/v1/auth/language', ['locale' => 'hi'], $this->bearer(Customer::factory()->create()))->assertUnauthorized();
    }

    public function test_e_mails_follow_the_choice_and_carry_every_language_without_one(): void
    {
        $admin = AdminUser::factory()->create();

        $both = $this->resetMailFor($admin);
        $this->assertSame(self::EN, $both->getSubject());
        $this->assertStringContainsString(self::HI, $both->getTextBody());
        $this->assertStringContainsString('Your password stays the same', $both->getTextBody());

        $admin->forceFill(['preferred_locale' => 'hi'])->save();
        $hindi = $this->resetMailFor($admin);
        $this->assertSame(self::HI, $hindi->getSubject());
        $this->assertStringContainsString('आपका पासवर्ड वही रहेगा', $hindi->getTextBody());
        $this->assertStringNotContainsString('Your password stays the same', $hindi->getTextBody().$hindi->getHtmlBody());
        $this->assertStringContainsString('<html lang="hi">', $hindi->getHtmlBody());
        $this->assertMatchesRegularExpression('~/admin/reset-password#[A-Za-z0-9]{64}~', $hindi->getTextBody());

        $admin->forceFill(['preferred_locale' => 'en'])->save();
        $english = $this->resetMailFor($admin);
        $this->assertSame(self::EN, $english->getSubject());
        $this->assertDoesNotMatchRegularExpression('/\p{Devanagari}/u', $english->getTextBody().$english->getHtmlBody());

        // A stored choice that is no longer offered falls back to what is available — never an empty or untranslated message.
        $admin->forceFill(['preferred_locale' => 'hi'])->save();
        config(['auth_security.notice_locales' => ['en']]);
        $fallback = $this->resetMailFor($admin);
        $this->assertSame(self::EN, $fallback->getSubject());
        $this->assertStringNotContainsString('auth.', $fallback->getTextBody());
    }

    public function test_an_invitation_can_be_sent_in_one_language_which_becomes_the_persons_choice(): void
    {
        $this->seed(RoleSeeder::class);
        $super = AdminUser::factory()->create();
        app(RoleService::class)->assign($super, Role::query()->where('principal_type', PrincipalType::AdminUser->value)->where('code', 'SUPER_ADMIN')->firstOrFail());
        $this->actingAsPrincipal($super);
        $invite = ['name' => 'Priya Nair', 'email' => 'priya.nair@foodonthego.example', 'role' => 'ANALYST'];

        $this->postJson('/api/v1/admin/users', $invite + ['locale' => 'fr'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/users', $invite + ['locale' => 'hi'])->assertCreated()->assertJson(['preferred_locale' => 'hi']);

        $mail = $this->lastMail();
        $this->assertSame('आपको FoodOnTheGo एडमिनिस्ट्रेशन में आमंत्रित किया गया है', $mail->getSubject());
        $this->assertStringNotContainsString('Choose your password', $mail->getTextBody());
        $this->assertSame('hi', AdminUser::query()->where('email', 'priya.nair@foodonthego.example')->value('preferred_locale'));

        $this->postJson('/api/v1/admin/users', ['name' => 'Tom Okafor', 'email' => 'tom.o@foodonthego.example', 'role' => 'ANALYST'])->assertCreated()->assertJson(['preferred_locale' => null]);
        $this->assertStringContainsString('अपना पासवर्ड चुनें', $this->lastMail()->getTextBody());
        $this->assertStringContainsString('Choose your password', $this->lastMail()->getTextBody());
    }
}
