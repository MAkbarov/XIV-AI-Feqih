<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;

class FileUploadController extends Controller
{
    public function uploadSiteLogo(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:1024', // 1MB limit
            'variant' => 'nullable|string|in:desktop_light,desktop_dark,mobile_light,mobile_dark,default'
        ]);

        try {
            $file = $request->file('image');
            $variant = $request->input('variant', 'default');

            $manager = new \Intervention\Image\ImageManager(new Driver());
            $image = $manager->read($file);

            // Resize to max 1024x1024 while preserving aspect
            if ($image->width() > 1024 || $image->height() > 1024) {
                $image->scaleDown(width: 1024, height: 1024);
            }

            $filename = 'brand-logo-' . time() . '.' . $file->getClientOriginalExtension();
            $path = 'brand/' . $filename;
            $encoded = $image->encodeByExtension($file->getClientOriginalExtension(), quality: 90);
            Storage::disk('public')->put($path, $encoded);

            $relative = Storage::disk('public')->url($path); // e.g. /storage/brand/filename
            $url = url($relative);

            // Ensure storage symlink exists; if not, fallback copy to public path
            try {
                if (!file_exists(public_path('storage'))) {
                    Artisan::call('storage:link');
                }
            } catch (\Exception $e) {
                // ignore
            }

            // If the expected file path under public is missing, copy to public/brand and adjust URL
            $publicStoragePath = public_path(ltrim($relative, '/'));
            if (!file_exists($publicStoragePath)) {
                $fallbackDir = public_path('brand');
                \Illuminate\Support\Facades\File::ensureDirectoryExists($fallbackDir);
                $sourcePath = Storage::disk('public')->path($path);
                $fallbackPath = $fallbackDir . DIRECTORY_SEPARATOR . $filename;
                try {
                    \Illuminate\Support\Facades\File::copy($sourcePath, $fallbackPath);
                    $url = url('brand/' . $filename);
                } catch (\Exception $e) {
                    // keep original URL
                }
            }

            // Persist per-variant if provided, otherwise default legacy key
            if (in_array($variant, ['desktop_light','desktop_dark','mobile_light','mobile_dark'])) {
                Settings::set('brand_logo_' . $variant, $url);
            } else {
                Settings::set('brand_logo_url', $url);
            }
            // Ensure brand mode switches to logo
            Settings::set('brand_mode', 'logo');

            return response()->json(['success' => true, 'url' => $url, 'variant' => $variant]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function uploadFavicon(Request $request)
    {
        $request->validate([
            'image' => 'required|file|mimes:png,jpg,jpeg,ico|max:512', // 512KB limit
        ]);

        try {
            $file = $request->file('image');
            $ext = strtolower($file->getClientOriginalExtension());

            if ($ext === 'ico') {
                $filename = 'favicon-' . time() . '.ico';
                $path = 'brand/' . $filename;
                Storage::disk('public')->put($path, file_get_contents($file));
            } else {
                $manager = new \Intervention\Image\ImageManager(new Driver());
                $image = $manager->read($file)->scaleDown(width: 64, height: 64);
                $filename = 'favicon-' . time() . '.' . $ext;
                $path = 'brand/' . $filename;
                $encoded = $image->encodeByExtension($ext, quality: 90);
                Storage::disk('public')->put($path, $encoded);
            }

            $relative = Storage::disk('public')->url($path);
            $url = url($relative);

            // Ensure storage symlink exists; if not, fallback copy to public path
            try {
                if (!file_exists(public_path('storage'))) {
                    Artisan::call('storage:link');
                }
            } catch (\Exception $e) {
                // ignore
            }

            // If the expected file path under public is missing, copy to public/brand and adjust URL
            $publicStoragePath = public_path(ltrim($relative, '/'));
            if (!file_exists($publicStoragePath)) {
                $fallbackDir = public_path('brand');
                File::ensureDirectoryExists($fallbackDir);
                $sourcePath = Storage::disk('public')->path($path);
                $fallbackPath = $fallbackDir . DIRECTORY_SEPARATOR . $filename;
                try {
                    File::copy($sourcePath, $fallbackPath);
                    $url = url('brand/' . $filename);
                } catch (\Exception $e) {
                    // keep original URL
                }
            }

            // Single favicon URL (no mode variants)
            Settings::set('favicon_url', $url);

            // Also copy to public/favicon.ext if possible for browser compatibility
            try {
                $publicTarget = public_path('favicon.' . ($ext === 'ico' ? 'ico' : $ext));
                @copy(Storage::disk('public')->path($path), $publicTarget);
            } catch (\Exception $e) {}

            return response()->json(['success' => true, 'url' => $url]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // Background upload methods removed - now handled by UserBackgroundController
}
