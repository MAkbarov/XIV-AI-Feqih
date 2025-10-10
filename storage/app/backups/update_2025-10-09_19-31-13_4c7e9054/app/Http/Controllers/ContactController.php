<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use App\Models\Settings;

class ContactController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Contact', [
            'contact' => [
                'title' => Settings::get('contact_title', 'Əlaqə'),
                'content' => Settings::get('contact_content', 'Bizimlə əlaqə saxlamaq üçün aşağıdakı e-poçtdan istifadə edin.'),
                'email' => Settings::get('contact_email', config('mail.from.address', 'admin@example.com')),
            ],
            // Navbar və layout üçün sayt başlığı və digər vizual parametrlər
            'settings' => [
                'site_name' => Settings::get('site_name', 'XIV AI'),
            ],
        ]);
    }
}
