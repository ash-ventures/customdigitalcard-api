<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Social\SocialLoginException;
use App\Services\Social\SocialLoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialUser;
use Throwable;

class SocialAuthController extends Controller
{
    private const PROVIDERS = ['google'];

    private const NAMES = ['google' => 'Google'];

    private const STATE_TTL_SECONDS = 600;

    private const CODE_TTL_SECONDS = 120;

    /**
     * Providers with credentials configured, so the login screen only shows usable buttons.
     */
    public function providers(): JsonResponse
    {
        return response()->json([
            'providers' => array_values(array_filter(
                self::PROVIDERS,
                fn (string $provider): bool => $this->configured($provider),
            )),
        ]);
    }

    public function redirect(string $provider): JsonResponse
    {
        $this->ensureSupported($provider);

        if (! $this->configured($provider)) {
            return response()->json([
                'message' => 'Log masuk dengan '.self::NAMES[$provider].' belum tersedia buat masa ini.',
            ], 422);
        }

        $state = Str::random(40);
        Cache::put($this->stateKey($state), ['provider' => $provider], self::STATE_TTL_SECONDS);

        $url = $this->driver($provider)
            ->with(['state' => $state])
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    public function callback(Request $request, string $provider, SocialLoginService $logins): RedirectResponse
    {
        $this->ensureSupported($provider);

        $state = (string) $request->input('state', '');
        $pending = $state !== '' ? Cache::pull($this->stateKey($state)) : null;

        // A missing or reused state means the callback did not start from our redirect endpoint.
        if (! is_array($pending) || ($pending['provider'] ?? null) !== $provider) {
            return $this->callbackFailure('state');
        }

        if ($request->filled('error')) {
            return $this->callbackFailure('denied');
        }

        if (blank($request->input('code'))) {
            return $this->callbackFailure('failed');
        }

        try {
            $socialUser = $this->driver($provider)->stateless()->user();

            $user = $logins->resolve($provider, [
                'id' => (string) $socialUser->getId(),
                'email' => $socialUser->getEmail(),
                'name' => $socialUser->getName(),
                'email_verified' => $this->emailVerified($socialUser),
            ]);
        } catch (SocialLoginException $exception) {
            return $this->callbackFailure($exception->reason);
        } catch (Throwable $exception) {
            report($exception);

            return $this->callbackFailure('failed');
        }

        $code = Str::random(64);
        Cache::put($this->codeKey($code), $user->getKey(), self::CODE_TTL_SECONDS);

        return redirect()->away($this->frontendUrl('/masuk/sosial').'?code='.urlencode($code));
    }

    /**
     * Swap the short-lived one-time code for a real API token.
     */
    public function exchange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:120'],
        ]);

        $userId = Cache::pull($this->codeKey($validated['code']));
        $user = is_numeric($userId) ? User::find((int) $userId) : null;

        if (! $user) {
            throw ValidationException::withMessages([
                'code' => ['Pautan log masuk tamat tempoh. Sila cuba lagi.'],
            ]);
        }

        return response()->json([
            'user' => $user->presented(),
            'token' => $user->createToken('customer-api')->plainTextToken,
        ]);
    }

    private function ensureSupported(string $provider): void
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
    }

    private function configured(string $provider): bool
    {
        $config = (array) config("services.{$provider}", []);

        return filled($config['client_id'] ?? null) && filled($config['client_secret'] ?? null);
    }

    private function driver(string $provider): GoogleProvider
    {
        $config = (array) config("services.{$provider}", []);

        // A stable callback URL is required: the provider only accepts the exact URI registered for the app.
        $config['redirect'] = filled($config['redirect'] ?? null)
            ? $config['redirect']
            : rtrim((string) config('app.url'), '/')."/api/v1/auth/social/{$provider}/callback";

        return Socialite::buildProvider(GoogleProvider::class, $config);
    }

    private function emailVerified(SocialUser $socialUser): bool
    {
        return filter_var($socialUser->getRaw()['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function callbackFailure(string $reason): RedirectResponse
    {
        return redirect()->away($this->frontendUrl('/masuk/sosial').'?error='.urlencode($reason));
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string) config('services.social.frontend_url'), '/').$path;
    }

    private function stateKey(string $state): string
    {
        return "social-state:{$state}";
    }

    private function codeKey(string $code): string
    {
        return "social-code:{$code}";
    }
}
