<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContactSettingsController extends Controller
{
    public function index(): Response
    {
        $data = [
            'contact_title' => Settings::get('contact_title', 'Əlaqə'),
            'contact_content' => Settings::get('contact_content', 'Bizimlə əlaqə saxlamaq üçün aşağıdakı e-poçtdan istifadə edin.'),
'contact_email' => Settings::get('contact_email', config('mail.from.address', 'admin@example.com')),
            'admin_email' => Settings::get('admin_email', config('mail.from.address', 'admin@example.com')),
        ];

        return Inertia::render('Admin/ContactSettings', [
            'contact' => $data,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'contact_title' => 'required|string|max:100',
            'contact_content' => 'nullable|string|max:20000',
            'contact_email' => 'nullable|email|max:255',
            'admin_email' => 'nullable|email|max:255',
        ]);

        foreach ($validated as $key => $value) {
            Settings::set($key, $value);
        }

        return redirect()->back()->with('success', 'Əlaqə səhifəsi və e-poçt parametrləri yeniləndi!');
    }
}
