<?php

namespace App\Support;

use App\Models\Invitation;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class InvitationSupport
{
    public static function contentRules(string $prefix = 'content'): array
    {
        return [
            $prefix => ['required', 'array'],
            "$prefix.bride" => ['required', 'string', 'max:70'],
            "$prefix.groom" => ['nullable', 'string', 'max:70'],
            "$prefix.brideNickname" => ['nullable', 'string', 'max:40'],
            "$prefix.groomNickname" => ['nullable', 'string', 'max:40'],
            "$prefix.nameOrder" => ['nullable', 'in:bride-first,groom-first'],
            "$prefix.nameLayout" => ['nullable', 'in:stacked,inline'],
            "$prefix.date" => ['nullable', 'date_format:Y-m-d'],
            "$prefix.time" => ['nullable', 'date_format:H:i'],
            "$prefix.endTime" => ['nullable', 'date_format:H:i'],
            "$prefix.venue" => ['nullable', 'string', 'max:120'],
            "$prefix.address" => ['nullable', 'string', 'max:300'],
            "$prefix.message" => ['nullable', 'string', 'max:300'],
            "$prefix.rsvpEnabled" => ['nullable', 'boolean'],
            "$prefix.contactName" => ['nullable', 'string', 'max:70'],
            "$prefix.contactPhone" => ['nullable', 'string', 'max:20'],
            "$prefix.additionalContacts" => ['nullable', 'array', 'max:3'],
            "$prefix.additionalContacts.*" => ['array'],
            "$prefix.additionalContacts.*.name" => ['nullable', 'string', 'max:70'],
            "$prefix.additionalContacts.*.phone" => ['nullable', 'string', 'max:20'],
            "$prefix.hashtag" => ['nullable', 'string', 'max:50'],
            "$prefix.couplePhoto" => ['nullable', 'string', 'max:1500000', 'regex:/^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+\/=]+$/'],
            "$prefix.musicUrl" => ['nullable', 'string', 'max:2048', 'regex:/^(https:\/\/[^\s@]+|\/songs\/[\w-]+\.mp3)$/'],
            "$prefix.schedule" => ['nullable', 'array', 'max:6'],
            "$prefix.schedule.*" => ['array'],
            "$prefix.schedule.*.time" => ['nullable', 'date_format:H:i'],
            "$prefix.schedule.*.type" => ['nullable', 'in:akad-nikah,wedding-reception,bride-reception,groom-reception,engagement,other'],
            "$prefix.schedule.*.date" => ['nullable', 'date_format:Y-m-d'],
            "$prefix.schedule.*.endTime" => ['nullable', 'date_format:H:i'],
            "$prefix.schedule.*.title" => ['nullable', 'string', 'max:80'],
            "$prefix.schedule.*.description" => ['nullable', 'string', 'max:180'],
            "$prefix.schedule.*.venue" => ['nullable', 'string', 'max:120'],
            "$prefix.schedule.*.address" => ['nullable', 'string', 'max:300'],
            "$prefix.schedule.*.mapsUrl" => ['nullable', 'url:https', 'max:2048'],
            "$prefix.schedule.*.dressCode" => ['nullable', 'string', 'max:80'],
            "$prefix.schedule.*.rsvpEnabled" => ['nullable', 'boolean'],
            "$prefix.rsvpDeadline" => ['nullable', 'date_format:Y-m-d'],
            "$prefix.lang" => ['nullable', 'in:ms,en'],
            "$prefix.donationQr" => ['nullable', 'string', 'max:1500000', 'regex:/^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+\/=]+$/'],
            "$prefix.donationNote" => ['nullable', 'string', 'max:120'],
            "$prefix.showCountdown" => ['nullable', 'boolean'],
            "$prefix.showWishes" => ['nullable', 'boolean'],
            "$prefix.showCalendar" => ['nullable', 'boolean'],
            "$prefix.design" => ['nullable', 'array'],
            "$prefix.design.nameFont" => ['nullable', 'in:'.implode(',', self::NAME_FONTS)],
            "$prefix.design.primary" => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            "$prefix.design.bodyTextColor" => ['nullable', 'regex:/^(primary|#[0-9a-fA-F]{6})$/'],
            "$prefix.design.accent" => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            "$prefix.design.nameScale" => ['nullable', 'integer', 'between:60,150'],
            "$prefix.design.nameWeight" => ['nullable', 'integer', 'in:300,400,500,600,700'],
            "$prefix.design.textScale" => ['nullable', 'integer', 'between:85,120'],
            "$prefix.design.letterSpacing" => ['nullable', 'numeric', 'between:-1,3'],
            "$prefix.design.textAlign" => ['nullable', 'in:center,left'],
            "$prefix.design.lineHeight" => ['nullable', 'integer', 'between:110,180'],
            "$prefix.design.bodyFont" => ['nullable', 'in:serif,sans,playfair,cinzel,cormorant,lora,montserrat,josefin'],
            "$prefix.design.showFrame" => ['nullable', 'boolean'],
            "$prefix.design.navFont" => ['nullable', 'in:serif,sans,playfair,cinzel,cormorant,lora,montserrat,josefin,'.implode(',', self::NAME_FONTS)],
            "$prefix.design.navScale" => ['nullable', 'integer', 'between:80,140'],
            "$prefix.design.pageText" => ['nullable', 'array'],
            "$prefix.design.pageText.home" => ['nullable', 'integer', 'between:80,130'],
            "$prefix.design.pageText.events" => ['nullable', 'integer', 'between:80,130'],
            "$prefix.design.pageText.wishes" => ['nullable', 'integer', 'between:80,130'],
            "$prefix.design.pageText.contact" => ['nullable', 'integer', 'between:80,130'],
            "$prefix.design.photoScale" => ['nullable', 'integer', 'between:60,140'],
            "$prefix.design.fieldScale" => ['nullable', 'integer', 'between:80,130'],
            "$prefix.design.textTone" => ['nullable', 'in:dark,light'],
            "$prefix.design.backgroundColor" => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            "$prefix.design.floralScale" => ['nullable', 'integer', 'between:75,130'],
            "$prefix.design.floralOffsetX" => ['nullable', 'integer', 'between:-10,10'],
            "$prefix.design.floralOffsetY" => ['nullable', 'integer', 'between:-10,10'],
            "$prefix.design.floralRotation" => ['nullable', 'integer', 'between:-12,12'],
            "$prefix.design.floralOpacity" => ['nullable', 'integer', 'between:25,100'],
            "$prefix.design.frameRadius" => ['nullable', 'integer', 'between:0,40'],
            "$prefix.design.frameOpacity" => ['nullable', 'integer', 'between:0,100'],
            "$prefix.design.navColor" => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            "$prefix.design.navOpacity" => ['nullable', 'integer', 'between:0,100'],
            "$prefix.design.intro" => ['nullable', 'boolean'],
            "$prefix.design.motion" => ['nullable', 'boolean'],
            "$prefix.design.background" => ['nullable', 'string', 'max:1500000', 'regex:/^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+\/=]+$/'],
        ];
    }

    public static function rsvpDeadline(?string $date): ?CarbonImmutable
    {
        return $date
            ? CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Kuala_Lumpur')->endOfDay()
            : null;
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
            $slug = Str::limit($base, 60, '').'-'.Str::lower(Str::random(8));
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

        return $t ? $t->toCatalog() : null;
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

    public static function supportPath(Invitation $invitation): ?string
    {
        $appKey = config('app.key');
        if ($invitation->status !== 'pending_payment' || ! is_string($appKey) || $appKey === '') {
            return null;
        }

        $token = hash_hmac('sha256', 'invitation-support:'.$invitation->slug, $appKey);

        return '/jemputan/'.$invitation->slug.'?support='.rawurlencode($token);
    }

    public static function hasValidSupportToken(Invitation $invitation, ?string $token): bool
    {
        $appKey = config('app.key');
        if ($invitation->status !== 'pending_payment' || ! is_string($appKey) || $appKey === '' || ! is_string($token)) {
            return false;
        }

        $expected = hash_hmac('sha256', 'invitation-support:'.$invitation->slug, $appKey);

        return hash_equals($expected, $token);
    }
}
