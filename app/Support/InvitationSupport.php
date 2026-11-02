<?php

namespace App\Support;

use App\Models\Invitation;
use App\Models\Order;
use Illuminate\Support\Str;

class InvitationSupport
{
    public static function contentRules(string $prefix = 'content'): array
    {
        return [
            $prefix => ['required', 'array'],
            "$prefix.bride" => ['required', 'string', 'max:70'],
            "$prefix.groom" => ['nullable', 'string', 'max:70'],
            "$prefix.date" => ['nullable', 'date_format:Y-m-d'],
            "$prefix.time" => ['nullable', 'date_format:H:i'],
            "$prefix.endTime" => ['nullable', 'date_format:H:i'],
            "$prefix.venue" => ['nullable', 'string', 'max:120'],
            "$prefix.address" => ['nullable', 'string', 'max:300'],
            "$prefix.message" => ['nullable', 'string', 'max:300'],
            "$prefix.rsvpEnabled" => ['nullable', 'boolean'],
            "$prefix.contactName" => ['nullable', 'string', 'max:70'],
            "$prefix.contactPhone" => ['nullable', 'string', 'max:20'],
            "$prefix.lang" => ['nullable', 'in:ms,en'],
            "$prefix.donationQr" => ['nullable', 'string', 'max:1500000', 'regex:/^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+\/=]+$/'],
            "$prefix.donationNote" => ['nullable', 'string', 'max:120'],
            "$prefix.showCountdown" => ['nullable', 'boolean'],
            "$prefix.showWishes" => ['nullable', 'boolean'],
            "$prefix.showCalendar" => ['nullable', 'boolean'],
            "$prefix.design" => ['nullable', 'array'],
            "$prefix.design.nameFont" => ['nullable', 'in:'.implode(',', self::NAME_FONTS)],
            "$prefix.design.primary" => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            "$prefix.design.accent" => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            "$prefix.design.nameScale" => ['nullable', 'integer', 'between:60,150'],
            "$prefix.design.textTone" => ['nullable', 'in:dark,light'],
            "$prefix.design.intro" => ['nullable', 'boolean'],
            "$prefix.design.motion" => ['nullable', 'boolean'],
            "$prefix.design.background" => ['nullable', 'string', 'max:1500000', 'regex:/^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+\/=]+$/'],
        ];
    }

    public const NAME_FONTS = [
        'great-vibes', 'italianno', 'sacramento', 'dancing-script', 'cinzel-decorative',
        'allura', 'parisienne', 'pinyon-script', 'alex-brush', 'playfair',
    ];

    public static function draftSlug(): string
    {
        return 'draft-'.Str::lower(Str::random(8));
    }

    public static function finalSlug(array $content): string
    {
        do {
            $base = Str::slug(trim(($content['bride'] ?? '').' '.($content['groom'] ?? ''))) ?: 'jemputan';
            $slug = Str::limit($base, 60, '').'-'.            Str::lower(Str::random(8));
        } while (Invitation::query()->where('slug', $slug)->exists());

        return $slug;
    }

    public static function title(Invitation $invitation): string
    {
        $content = $invitation->content ?? [];

        return trim(($content['bride'] ?? '').(filled($content['groom'] ?? null) ? ' & '.$content['groom'] : ''));
    }

    public static function template(Invitation $invitation): ?array
    {
        $t = $invitation->template;

        return $t ? ['slug' => $t->slug, 'name' => $t->name, 'theme' => $t->theme, 'category' => $t->category] : null;
    }

    public static function order(?Order $order): ?array
    {
        if (! $order) {
            return null;
        }

        return [
            'reference' => $order->reference,
            'status' => $order->status,
            'amount_sen' => $order->amount_sen,
            'payment_url' => $order->status === 'pending' ? $order->payment_url : null,
        ];
    }

    public static function publicPath(Invitation $invitation): ?string
    {
        // Friendly URL. The slug ends with a random string, so cards cannot be guessed or enumerated.
        // Old links (/i/{slug}) are redirected by the frontend (next.config.ts).
        return $invitation->status === 'published' ? '/jemputan/'.$invitation->slug : null;
    }
}
