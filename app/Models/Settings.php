<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Settings extends Model
{
    protected $fillable = ['key', 'value'];
    
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * Get a setting value by key
     */
    public static function get($key, $default = null)
    {
        return Cache::remember("setting_{$key}", 3600, function () use ($key, $default) {
            $setting = static::find($key);
            return $setting ? $setting->value : $default;
        });
    }

    /**
     * Get a boolean setting value by key
     * Properly converts string '1'/'0' values from database to boolean
     */
    public static function getBool($key, $default = false)
    {
        $value = static::get($key, $default);
        if ($value === '1' || $value === 1 || $value === true) {
            return true;
        }
        if ($value === '0' || $value === 0 || $value === false) {
            return false;
        }
        return (bool) $default;
    }

    /**
     * Set a setting value
     */
    public static function set($key, $value)
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
        
        Cache::forget("setting_{$key}");
        Cache::forget('all_settings');
        
        return true;
    }

    /**
     * Get all settings as an associative array
     */
    public static function getAllSettings()
    {
        return Cache::remember('all_settings', 3600, function () {
            return static::all()->pluck('value', 'key')->toArray();
        });
    }

    /**
     * Clear settings cache
     */
    public static function clearCache()
    {
        $keys = static::all()->pluck('key');
        foreach ($keys as $key) {
            Cache::forget("setting_{$key}");
        }
        Cache::forget('all_settings');
    }

    /**
     * Get default settings
     */
    public static function getDefaults()
    {
        return [
            'site_name' => 'XIV AI Chatbot Platform',
            'primary_color' => '#6366f1',
            'secondary_color' => '#8b5cf6',
            'accent_color' => '#fbbf24',
            'background_gradient' => 'linear-gradient(135deg, #f9fafb 0%, #ffffff 100%)',
            'text_color' => '#1f2937',
            'chatbot_name' => 'AI Assistant',
            'guest_message_limit' => 10,
            'guest_character_limit' => 500,
            'enter_sends_message' => true,
            'active_ai_provider' => null,
            
            // AI Professional Settings
            'ai_system_prompt' => 'Sən Şiə İslam mövzularında professional ixtisaslaşmış dini məsləhətçi botsan. Bütün cavabların Şiə məzhəbinin təlimlərinə uyğun olmalıdır. Hər cavabda müctehidlərin fətvalarına istinad et və mənbə göstər.',
            'ai_use_knowledge_base' => true,
            'ai_strict_mode' => true,
            'ai_topic_restrictions' => '',
            'ai_typing_speed' => 50,
            'ai_thinking_time' => 1000,
            'ai_response_type' => 'typewriter',
        ];
    }

    /**
     * Initialize default settings
     */
    public static function initializeDefaults()
    {
        $defaults = static::getDefaults();
        foreach ($defaults as $key => $value) {
            static::firstOrCreate(
                ['key' => $key],
                ['value' => $value]
            );
        }
    }
}
