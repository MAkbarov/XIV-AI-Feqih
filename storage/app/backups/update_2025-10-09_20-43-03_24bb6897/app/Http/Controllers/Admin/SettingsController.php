<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Http\Controllers\Admin\Traits\HasFooterData;

class SettingsController extends Controller
{
    use HasFooterData;
    public function index(): Response
    {
        $settings = [
            'chatbot_name' => Settings::get('chatbot_name', 'AI Assistant'),
            'message_input_limit' => Settings::get('message_input_limit', Settings::get('guest_input_limit', '500')),
            'ai_output_limit' => Settings::get('ai_output_limit', Settings::get('guest_output_limit', '1000')),
            'enter_sends_message' => Settings::getBool('enter_sends_message', true),
            'ai_typing_speed' => Settings::get('ai_typing_speed', '50'),
            'ai_thinking_time' => Settings::get('ai_thinking_time', '1000'),
            'ai_response_type' => Settings::get('ai_response_type', 'typewriter'),
            'ai_use_knowledge_base' => Settings::getBool('ai_use_knowledge_base', true),
            'ai_strict_mode' => Settings::getBool('ai_strict_mode', true),
            'ai_topic_restrictions' => Settings::get('ai_topic_restrictions', ''),
            'ai_internet_blocked' => Settings::getBool('ai_internet_blocked', true),
            'ai_external_learning_blocked' => Settings::getBool('ai_external_learning_blocked', true),
            'ai_super_strict_mode' => Settings::getBool('ai_super_strict_mode', false),
            // Footer Settings
            'footer_text' => Settings::get('footer_text', '© 2025 XIV AI. Bütün hüquqlar qorunur.'),
            'footer_enabled' => Settings::getBool('footer_enabled', true),
            'footer_text_color' => Settings::get('footer_text_color', '#6B7280'),
            'footer_author_text' => Settings::get('footer_author_text', 'Developed by DeXIV'),
            'footer_author_color' => Settings::get('footer_author_color', '#6B7280'),
            // Additional Footer Elements (removed security texts)
            // Chat Disclaimer
            'chat_disclaimer_text' => Settings::get('chat_disclaimer_text', 'Çatbotun cavablarını yoxlayın, səhv edə bilər!'),
            // Site Settings
'site_name' => Settings::get('site_name', 'XIV AI Chatbot Platform'),
            'brand_mode' => Settings::get('brand_mode', 'icon'), // icon | logo | none
            'brand_icon_name' => Settings::get('brand_icon_name', 'nav_chat'),
            'brand_logo_url' => Settings::get('brand_logo_url', ''),
            'favicon_url' => Settings::get('favicon_url', ''),
            // Admin Settings
            'admin_email' => Settings::get('admin_email', config('mail.from.address', 'admin@example.com')),
            // Chat Background Settings
            'chat_background_type' => Settings::get('chat_background_type', 'default'),
            'chat_background_color' => Settings::get('chat_background_color', '#f3f4f6'),
            'chat_background_gradient' => Settings::get('chat_background_gradient', 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'),
            'chat_background_image' => Settings::get('chat_background_image', ''),
        ];

        return Inertia::render('Admin/Settings', $this->addFooterDataToResponse([
            'settings' => $settings,
        ]));
    }

    public function update(Request $request)
    {
        try {
            $validated = $request->validate([
                'chatbot_name' => 'required|string|max:255',
'message_input_limit' => 'required|integer|min:10|max:10000',
                'ai_output_limit' => 'required|integer|min:10|max:20000',
                'enter_sends_message' => 'boolean',
                'ai_typing_speed' => 'required|integer|min:10|max:500',
                'ai_thinking_time' => 'required|integer|min:0|max:5000',
                'ai_response_type' => 'required|in:typewriter,instant',
                'ai_use_knowledge_base' => 'boolean',
                'ai_strict_mode' => 'boolean',
                'ai_topic_restrictions' => 'nullable|string|max:5000',
                'ai_internet_blocked' => 'boolean',
                'ai_external_learning_blocked' => 'boolean',
                'ai_super_strict_mode' => 'boolean',
                // Footer validation
                'footer_text' => 'required|string|max:500',
                'footer_enabled' => 'boolean',
                'footer_text_color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
                'footer_author_text' => 'required|string|max:500',
                'footer_author_color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
                // Additional footer elements validation (removed security texts)
                // Chat disclaimer validation
                'chat_disclaimer_text' => 'required|string|max:300',
                // Site validation
'site_name' => 'required|string|max:100',
                'brand_mode' => 'required|in:icon,logo,none',
                'brand_icon_name' => 'nullable|string|max:100',
                'brand_logo_url' => 'nullable|string|max:500',
                'favicon_url' => 'nullable|string|max:500',
                // Admin validation
                'admin_email' => 'nullable|email|max:255',
                // Chat background validation
                'chat_background_type' => 'required|in:default,solid,gradient,image',
                'chat_background_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
                'chat_background_gradient' => 'nullable|string|max:500',
                'chat_background_image' => 'nullable|string|max:500',
            ]);

            // Do not overwrite existing admin_email with null/empty values
            if (array_key_exists('admin_email', $validated) && ($validated['admin_email'] === null || $validated['admin_email'] === '')) {
                unset($validated['admin_email']);
            }

            // Map legacy keys if present
            if (array_key_exists('guest_input_limit', $validated)) {
                $validated['message_input_limit'] = $validated['guest_input_limit'];
                unset($validated['guest_input_limit']);
            }
            if (array_key_exists('guest_output_limit', $validated)) {
                $validated['ai_output_limit'] = $validated['guest_output_limit'];
                unset($validated['guest_output_limit']);
            }

            foreach ($validated as $key => $value) {
                Settings::set($key, $value);
            }

            return back()->with('success', 'Parametrlər uğurla yeniləndi!');
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Settings validation error', [
                'errors' => $e->errors(),
                'request_data' => $request->all()
            ]);
            return back()->withErrors($e->errors())->with('error', 'Parametrləri yoxlayın və yenidən cəhd edin!');
        } catch (\Exception $e) {
            \Log::error('Settings update error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            return back()->with('error', 'Parametrləri yeniləyərkən xəta baş verdi: ' . $e->getMessage());
        }
    }

    /**
     * Get theme settings as JSON for frontend consumption
     */
    public function theme()
    {
        return response()->json([
            'primary_color' => Settings::get('primary_color', '#10b981'),
            'secondary_color' => Settings::get('secondary_color', '#97a5a1'),
            'accent_color' => Settings::get('accent_color', '#fbbf24'),
            'background_gradient' => Settings::get('background_gradient', 'linear-gradient(135deg, green 10%, black 100%)'),
            'text_color' => Settings::get('text_color', '#1f2937'),
            // Chat background settings
            'chat_background_type' => Settings::get('chat_background_type', 'default'),
            'chat_background_color' => Settings::get('chat_background_color', '#f3f4f6'),
            'chat_background_gradient' => Settings::get('chat_background_gradient', 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'),
            'chat_background_image' => Settings::get('chat_background_image', ''),
        ]);
    }
}
