<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group', 'description'];

    /**
     * Get typed value
     */
    public function getTypedValue()
    {
        return match($this->type) {
            'boolean' => $this->value === 'true' || $this->value === '1',
            'number' => is_numeric($this->value) ? (float) $this->value : 0,
            'json' => json_decode($this->value, true),
            default => $this->value,
        };
    }

    /**
     * Static helper to get a setting value
     */
    public static function get($key, $default = null)
    {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->getTypedValue() : $default;
    }

    /**
     * Static helper to set a setting value
     */
    public static function set($key, $value, $type = 'string', $group = 'general'): void
    {
        $valueToStore = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $valueToStore, 'type' => $type, 'group' => $group]
        );
    }
}
