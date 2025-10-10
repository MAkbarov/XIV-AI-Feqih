<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class AiProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'driver',
        'model',
        'api_key',
        'base_url',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Encrypt API key on set
    public function setApiKeyAttribute(?string $value): void
    {
        if ($value !== null && $value !== '') {
            $this->attributes['api_key'] = Crypt::encryptString($value);
        } elseif ($value === null) {
            $this->attributes['api_key'] = null;
        }
        // If empty string, don't change existing value
    }

    // Decrypt API key on get
    public function getApiKeyAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            // If decryption fails (e.g., APP_KEY changed), do not break the UI
            return null;
        }
    }

    public static function getActive(): ?self
    {
        return static::where('is_active', true)->first();
    }
}
