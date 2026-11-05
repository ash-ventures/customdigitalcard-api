<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TemplateCategory extends Model
{
    protected $fillable = ['name', 'sort_order', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }
}
