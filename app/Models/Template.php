<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Template extends Model
{
    protected $fillable = [
        'code',
        'slug',
        'name',
        'category',
        'theme',
        'description',
        'is_premium',
        'is_active',
        'is_custom',
        'sort_order',
        'configuration',
        'background_path',
    ];

    protected function casts(): array
    {
        return [
            'is_premium' => 'boolean',
            'is_active' => 'boolean',
            'is_custom' => 'boolean',
            'configuration' => 'array',
        ];
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public static function codeFor(int $id): string
    {
        return sprintf('TPL-%04d', $id);
    }

    public function backgroundUrl(): ?string
    {
        if (! $this->background_path) {
            return null;
        }

        return '/api/v1/templates/'.$this->code.'/background?v='.($this->updated_at?->timestamp ?? 0);
    }

    /** Shape shared by the public catalog and invitation payloads. */
    public function toCatalog(): array
    {
        return [
            'code' => $this->code,
            'slug' => $this->slug,
            'name' => $this->name,
            'category' => $this->category,
            'theme' => $this->theme,
            'description' => $this->description,
            'is_premium' => $this->is_premium,
            'is_custom' => $this->is_custom,
            'sort_order' => $this->sort_order,
            'mood' => $this->configuration['mood'] ?? null,
            'text_tone' => $this->configuration['text_tone'] ?? null,
            'accent_color' => $this->configuration['accent_color'] ?? null,
            'background_url' => $this->backgroundUrl(),
        ];
    }
}
