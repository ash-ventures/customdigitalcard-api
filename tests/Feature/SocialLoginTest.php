<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialUser;
use Mockery;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.social.frontend_url' => 'http://127.0.0.1:3000',
            'services.google.client_id' => 'google-client',
            'services.google.client_secret' => 'google-secret',
            'services.google.redirect' => null,
        ]);
    }

    public function test_google_is_offered_once_credentials_exist(): void
    {
        $this->getJson('/api/v1/auth/social/providers')->assertOk()->assertExactJson(['providers' => ['google']]);

        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->getJson('/api/v1/auth/social/providers')->assertOk()->assertExactJson(['providers' => []]);
    }

    public function test_redirect_returns_the_provider_url_with_a_single_use_state(): void
    {
        $url = $this->getJson('/api/v1/auth/social/google/redirect')->assertOk()->json('url');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth?', $url);

        $query = $this->queryOf($url);
        $this->assertSame('google-client', $query['client_id']);
        $this->assertSame(config('app.url').'/api/v1/auth/social/google/callback', $query['redirect_uri']);
        $this->assertArrayHasKey('state', $query);
    }

    public function test_unconfigured_and_unknown_providers_are_rejected(): void
    {
        config(['services.google.client_id' => null]);

        $this->getJson('/api/v1/auth/social/google/redirect')->assertStatus(422);
        $this->getJson('/api/v1/auth/social/facebook/redirect')->assertNotFound();
        $this->get('/api/v1/auth/social/facebook/callback?state=x&code=y')->assertNotFound();
    }

    public function test_callback_creates_an_account_and_the_one_time_code_issues_a_token(): void
    {
        $state = $this->startLogin('google');

        $this->fakeProviderUser(SocialUser::fake([
            'id' => 'google-123',
            'name' => 'Aminah Ali',
            'email' => 'Aminah@Example.test',
            'email_verified' => true,
        ]));

        $callback = $this->get("/api/v1/auth/social/google/callback?state={$state}&code=auth-code");
        $callback->assertRedirect();

        $code = $this->queryOf($callback->headers->get('Location'))['code'];

        $exchange = $this->postJson('/api/v1/auth/social/exchange', ['code' => $code])->assertOk();
        $exchange->assertJsonPath('user.email', 'aminah@example.test');
        $exchange->assertJsonPath('user.name', 'Aminah Ali');
        $this->assertNotEmpty($exchange->json('token'));

        $user = User::where('email', 'aminah@example.test')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-123',
        ]);
        $this->assertDatabaseHas('customers', ['user_id' => $user->id, 'email' => 'aminah@example.test']);

        // The one-time code cannot be replayed.
        $this->postJson('/api/v1/auth/social/exchange', ['code' => $code])->assertUnprocessable();
    }

    public function test_repeated_sign_in_reuses_the_linked_account(): void
    {
        $user = User::factory()->create(['email' => 'aminah@example.test']);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'google-123']);

        $state = $this->startLogin('google');
        $this->fakeProviderUser(SocialUser::fake(['id' => 'google-123', 'name' => 'Aminah', 'email' => null]));

        $callback = $this->get("/api/v1/auth/social/google/callback?state={$state}&code=auth-code");
        $code = $this->queryOf($callback->headers->get('Location'))['code'];

        $this->postJson('/api/v1/auth/social/exchange', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->assertSame(1, User::count());
    }

    public function test_existing_account_is_linked_only_when_the_provider_verified_the_email(): void
    {
        User::factory()->create(['email' => 'aminah@example.test']);

        $state = $this->startLogin('google');
        $this->fakeProviderUser(SocialUser::fake([
            'id' => 'google-123',
            'email' => 'aminah@example.test',
            'email_verified' => false,
        ]));

        $this->get("/api/v1/auth/social/google/callback?state={$state}&code=auth-code")
            ->assertRedirect('http://127.0.0.1:3000/masuk/sosial?error=unverified');

        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_callback_rejects_a_state_it_did_not_issue(): void
    {
        $this->get('/api/v1/auth/social/google/callback?state=forged&code=auth-code')
            ->assertRedirect('http://127.0.0.1:3000/masuk/sosial?error=state');
    }

    public function test_a_denied_consent_returns_to_the_login_screen(): void
    {
        $state = $this->startLogin('google');

        $this->get("/api/v1/auth/social/google/callback?state={$state}&error=access_denied")
            ->assertRedirect('http://127.0.0.1:3000/masuk/sosial?error=denied');
    }

    private function startLogin(string $provider): string
    {
        $url = $this->getJson("/api/v1/auth/social/{$provider}/redirect")->assertOk()->json('url');

        return $this->queryOf($url)['state'];
    }

    private function fakeProviderUser(SocialUser $user): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($user);

        Socialite::shouldReceive('buildProvider')->andReturn($provider);
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(?string $url): array
    {
        $query = [];
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        return $query;
    }
}
