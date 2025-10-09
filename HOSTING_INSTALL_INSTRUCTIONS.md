# XIV AI Feqih - Hosting Quraşdırma Təlimatları

## 1. Faylları hostinqə yükləyin
Bu ZIP faylını açıb bütün faylları web server-in root qovluğuna (məsələn: `public_html` və ya `wwwroot`) yükləyin.

## 2. .env faylını yaradın
```bash
# .env.production faylını kopyalayın və .env adlandırın
cp .env.production .env
```

## 3. .env faylını redaktə edin
`.env` faylını açıb bu parametrləri öz server məlumatları ilə dəyişdirin:

```env
APP_KEY=                     # php artisan key:generate ilə yaradılacaq
APP_URL=https://your-domain.com

DB_HOST=your-db-host
DB_DATABASE=your-database-name
DB_USERNAME=your-db-username
DB_PASSWORD=your-db-password

MAIL_USERNAME=your-email@domain.com
MAIL_PASSWORD=your-email-password
MAIL_FROM_ADDRESS=your-email@domain.com
```

## 4. Terminal/SSH əmrləri (ardıcıllıqla işə salın)

```bash
# 1. Dependencies yükləyin
composer install --no-dev --optimize-autoloader

# 2. APP_KEY generate edin
php artisan key:generate

# 3. Database migration işə salın
php artisan migrate

# 4. Database seed edin (admin user və default ayarlar)
php artisan db:seed

# 5. Storage linkini yaradın
php artisan storage:link

# 6. Cache və optimize edin
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 7. Qovluq icazələrini təyin edin (Linux/Unix üçün)
chmod -R 755 storage
chmod -R 755 bootstrap/cache
```

## 5. Web Server Konfiqurasiyası

### Apache (.htaccess)
`.htaccess` faylı artıq mövcuddur, əlavə konfiqurasiya lazım deyil.

### Nginx
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
}
```

## 6. Son yoxlama
- `https://your-domain.com` ünvanına daxil olun
- Admin panelə daxil olmaq üçün: `https://your-domain.com/admin`
- Default admin: email və ya verilənlər bazasında yaradılan user

## Qeydlər:
- **XAMPP Redis xətası**: Production serverdə Redis yükləmək lazım deyilsə, `.env`-də `REDIS_` parametrlərini silin
- **File permissions çox vacibdir**: `storage` və `bootstrap/cache` qovluqları yazıla bilən olmalıdır
- **Database connection**: Hosting provaidin verilənlər bazası məlumatları ilə `.env` faylını yeniləyin

## Xətaların həlli:
- **Session store not set**: `php artisan config:cache` işə salın
- **Key not set**: `php artisan key:generate` işə salın
- **Database connection**: `.env` faylında DB parametrlərini yoxlayın
- **500 error**: `storage/logs/laravel.log` faylına baxın