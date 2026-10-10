<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FieldConfiguration extends Model
{
    protected $fillable = [
        'module', 'field_name', 'field_key', 'visible', 'required',
        'mode', 'default_value', 'calculation_rule', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
            'required' => 'boolean',
        ];
    }

    public static function visibleFor(string $module): array
    {
        return static::where('module', $module)
            ->where('visible', true)
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    public static function isVisible(string $module, string $fieldKey): bool
    {
        $cfg = static::where('module', $module)->where('field_key', $fieldKey)->first();
        return $cfg ? (bool) $cfg->visible : true;
    }
}
