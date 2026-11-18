<?php

namespace App\Services\Social;

use App\Models\Customer;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Str;

class SocialLoginService
{
    /**
     * Find, link or create the customer behind a provider profile.
     *
     * @param  array{id: string, email?: string|null, name?: string|null, email_verified?: bool}  $profile
     */
    public function resolve(string $provider, array $profile): User
    {
        $providerUserId = trim((string) ($profile['id'] ?? ''));

        if ($providerUserId === '') {
            throw new SocialLoginException('failed');
        }

        $account = SocialAccount::query()
            ->with('user')
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($account?->user) {
            return $account->user;
        }

        $email = Str::lower(trim((string) ($profile['email'] ?? '')));
        $name = trim((string) ($profile['name'] ?? ''));
        $emailVerified = (bool) ($profile['email_verified'] ?? false);

        $user = $email !== '' ? User::where('email', $email)->first() : null;

        // Never attach a provider identity to an account whose ownership the provider has not proven.
        if ($user && ! $emailVerified) {
            throw new SocialLoginException('unverified');
        }

        if (! $user && $email === '') {
            throw new SocialLoginException('email');
        }

        $user ??= User::create([
            'name' => $name !== '' ? $name : Str::before($email, '@'),
            'email' => $email,
            'password' => null,
            'marketing_consent' => false,
        ]);

        if ($emailVerified && ! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->socialAccounts()->firstOrCreate([
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
        ]);

        Customer::capture($user->name, $user->email, $user->phone, false, $provider, $user);

        return $user;
    }
}
