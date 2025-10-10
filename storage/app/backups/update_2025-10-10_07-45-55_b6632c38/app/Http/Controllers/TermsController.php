<?php

namespace App\Http\Controllers;

use App\Models\TermsAndPrivacy;
use App\Models\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TermsController extends Controller
{
    public function terms()
    {
        $terms = TermsAndPrivacy::getTerms();
        $settings = Settings::getAllSettings();
        
        // If no terms found, create default
        if (!$terms) {
            $terms = (object) [
                'title' => 'İstifadə Şərtləri',
                'content' => '<h1>İstifadə Şərtləri</h1><p>Bu AI çatbot platformasından istifadə etməklə aşağıdaki şərtləri qəbul etmiş olursunuz:</p><ul><li>AI cavabları hər zaman düzgün olmaya bilər və yoxlanılmalıdır</li><li>Mühüm qərarlar üçün mütəxəssis məsləhəti alın</li><li>Şəxsi məlumatlarınızı paylaşmayın</li></ul>'
            ];
        }
        
        // Get theme colors from settings
        $theme = [
            'primary_color' => Settings::get('primary_color', '#6366F1'),
            'secondary_color' => Settings::get('secondary_color', '#EC4899'),
            'accent_color' => Settings::get('accent_color', '#fbbf24'),
            'background_gradient' => Settings::get('background_gradient', 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'),
            'text_color' => Settings::get('text_color', '#1f2937')
        ];
        
        return Inertia::render('Terms', [
            'terms' => $terms,
            'theme' => $theme,
            'settings' => $settings
        ]);
    }
    
    public function privacy()
    {
        $privacy = TermsAndPrivacy::getPrivacy();
        $settings = Settings::getAllSettings();
        
        // If no privacy found, create default
        if (!$privacy) {
            $privacy = (object) [
                'title' => 'Məxfilik Siyasəti',
                'content' => '<h1>Məxfilik Siyasəti</h1><p>Biz sizin məxfiliyinizə hörmət edirik və şəxsi məlumatlarınızın qorunması üçün lazımi tədbirlər görürük:</p><ul><li>Söhbətləriniz yalnız xidmət keyfiyyətini yaxşılaşdırmaq üçün istifadə edilir</li><li>Qonaq kimi istifadə edərkən məlumatlarınız lokal saxlanılır</li><li>İstənilən vaxt söhbət tarixçənizi siləbilərsiniz</li></ul>'
            ];
        }
        
        // Get theme colors from settings
        $theme = [
            'primary_color' => Settings::get('primary_color', '#6366F1'),
            'secondary_color' => Settings::get('secondary_color', '#EC4899'),
            'accent_color' => Settings::get('accent_color', '#fbbf24'),
            'background_gradient' => Settings::get('background_gradient', 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'),
            'text_color' => Settings::get('text_color', '#1f2937')
        ];
        
        return Inertia::render('Privacy', [
            'privacy' => $privacy,
            'theme' => $theme,
            'settings' => $settings
        ]);
    }
}
