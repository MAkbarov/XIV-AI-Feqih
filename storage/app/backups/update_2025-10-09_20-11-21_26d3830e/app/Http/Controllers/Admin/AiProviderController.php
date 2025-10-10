<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Http\Controllers\Admin\Traits\HasFooterData;

class AiProviderController extends Controller
{
    use HasFooterData;
    public function index(): Response
    {
        return Inertia::render('Admin/Providers/Index', $this->addFooterDataToResponse([
            // Select only non-sensitive columns to avoid decrypting api_key during listing
            'providers' => AiProvider::select('id','name','driver','model','base_url','is_active')
                ->orderByDesc('is_active')
                ->get(),
        ]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'driver' => 'required|in:openai,anthropic,deepseek,custom',
            'model' => 'nullable|string|max:255',
            'api_key' => 'nullable|string|max:8192',
            'base_url' => 'nullable|url',
            'is_active' => 'boolean',
        ]);

        // Set default values for DeepSeek
        if (($data['driver'] ?? '') === 'deepseek') {
            // DeepSeek is OpenAI-compatible, set default values
            $data['base_url'] = $data['base_url'] ?: 'https://api.deepseek.com/v1';
            if (empty($data['model'])) {
                $data['model'] = 'deepseek-chat'; // sensible default model name
            }
        }

        if (!empty($data['is_active'])) {
            AiProvider::query()->update(['is_active' => false]);
        }

        AiProvider::create($data);

        return back()->with('success', 'Provider saved');
    }

    public function update(Request $request, AiProvider $provider)
    {
        // Check if this is just a toggle request (only is_active field)
        if ($request->has('is_active') && count($request->except(['_token', '_method'])) === 1) {
            $isActive = $request->boolean('is_active');
            
            // If activating this provider, deactivate all others first
            if ($isActive) {
                AiProvider::where('id', '!=', $provider->id)->update(['is_active' => false]);
            }
            
            // Update the provider status
            $provider->update(['is_active' => $isActive]);
            
            // Return Inertia response, not JSON
            return back()->with('success', $isActive ? 'Provayder aktivləşdirildi' : 'Provayder deaktivləşdirildi');
        }
        
        // Full update validation for form edits
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'driver' => 'required|in:openai,anthropic,deepseek,custom',
            'model' => 'nullable|string|max:255',
            'api_key' => 'nullable|string|max:8192',
            'base_url' => 'nullable|url',
            'is_active' => 'boolean',
        ]);

        // Set default values for DeepSeek
        if (($data['driver'] ?? '') === 'deepseek') {
            // DeepSeek is OpenAI-compatible, set default values
            $data['base_url'] = $data['base_url'] ?: 'https://api.deepseek.com/v1';
            if (empty($data['model'])) {
                $data['model'] = 'deepseek-chat';
            }
        }

        // If API key is empty in edit mode, don't update it
        if (empty($data['api_key'])) {
            unset($data['api_key']);
        }

        // If setting as active, deactivate all others
        if (!empty($data['is_active'])) {
            AiProvider::where('id', '!=', $provider->id)->update(['is_active' => false]);
        }

        $provider->update($data);

        return back()->with('success', 'Provayder yeniləndi');
    }

    public function destroy(AiProvider $provider)
    {
        $provider->delete();
        return back()->with('success', 'Provider deleted');
    }
}

