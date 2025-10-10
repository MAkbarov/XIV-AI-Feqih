<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use App\Models\Settings;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Apply mail settings from database (runtime override)
        try {
            $default = Settings::get('mail_mailer', config('mail.default', 'smtp'));
            $host = Settings::get('mail_host', config('mail.mailers.smtp.host'));
            $port = (int) Settings::get('mail_port', config('mail.mailers.smtp.port'));
            $username = Settings::get('mail_username', config('mail.mailers.smtp.username'));
            $password = Settings::get('mail_password', config('mail.mailers.smtp.password'));
            $encryption = Settings::get('mail_encryption', config('mail.mailers.smtp.scheme'));
            $fromAddr = Settings::get('mail_from_address', config('mail.from.address'));
            $fromName = Settings::get('mail_from_name', config('mail.from.name'));

            if ($default) config(['mail.default' => $default]);
            if ($host) config(['mail.mailers.smtp.host' => $host]);
            if ($port) config(['mail.mailers.smtp.port' => $port]);
            if ($username !== null) config(['mail.mailers.smtp.username' => $username]);
            if ($password !== null) config(['mail.mailers.smtp.password' => $password]);
            if ($encryption && $encryption !== 'none') config(['mail.mailers.smtp.scheme' => $encryption]);
            if ($fromAddr) config(['mail.from.address' => $fromAddr]);
            if ($fromName) config(['mail.from.name' => $fromName]);
        } catch (\Throwable $e) {
            // Ignore runtime config errors to avoid blocking app
        }
    }
}

