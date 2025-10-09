# XIV AI Fəqih

**Advanced Islamic AI Chatbot Platform**

![Version](https://img.shields.io/badge/version-v1.1.4-blue.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)
![Laravel](https://img.shields.io/badge/Laravel-^9.0-red.svg)
![PHP](https://img.shields.io/badge/PHP-^8.1-purple.svg)

## 📖 Haqqında

XIV AI Fəqih - İslam dininin fundamental məsələləri üzrə AI əsaslı chatbot platformasıdır. Laravel və React texnologiyalarından istifadə edərək hazırlanmışdır.

## ✨ Xüsusiyyətlər

- 🤖 **AI İnteqrasiya**: OpenAI və Anthropic dəstəyi
- 🌙 **İslami Fəqih**: İslam hüququ və fəqih məsələləri
- 🎨 **Modern UI/UX**: React və Tailwind CSS
- 📱 **Responsive Dizayn**: Mobil və desktop uyğunluğu
- 🔐 **Təhlükəsizlik**: Laravel Sanctum authentication
- 📊 **Admin Panel**: Tam idarəetmə sistemi
- 🔄 **Auto Update**: GitHub əsaslı avtomatik yenilənmə

## 🚀 Quraşdırma

### Tələblər

- PHP >= 8.1
- Laravel ^9.0
- MySQL >= 5.7
- Node.js >= 16.0
- Composer
- NPM

### Quraşdırma Addımları

1. **Layihəni klonlayın**
   ```bash
   git clone https://github.com/MAkbarov/XIV-AI-Feqih.git
   cd XIV-AI-Feqih
   ```

2. **Dependency-ləri quraşdırın**
   ```bash
   composer install
   npm install
   ```

3. **Environment faylını konfiq edin**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Database migrationslarını çalışdırın**
   ```bash
   php artisan migrate
   ```

5. **Frontend assets-ləri build edin**
   ```bash
   npm run build
   ```

6. **Serveri başladın**
   ```bash
   php artisan serve
   ```

## 🔧 Konfiqurasiya

### AI Providers

`.env` faylında AI provider açarlarınızı əlavə edin:

```env
OPENAI_API_KEY=your_openai_key
ANTHROPIC_API_KEY=your_anthropic_key
```

### Timezone

Azerbaycan timezone-u üçün:

```env
APP_TIMEZONE=Asia/Baku
```

## 📊 Admin Panel

Admin panelinə `/admin` route-u ilə daxil ola bilərsiniz. İlk admin istifadəçisini yaratmaq üçün:

```bash
php artisan db:seed --class=AdminUserSeeder
```

## 🔄 Yenilənmə Sistemi

Sistem GitHub üzərindən avtomatik yenilənmə dəstəyi təmin edir:

1. Admin panel → System → Updates
2. "Yenilikləri Yoxla" düyməsinə basın
3. Mövcud yenilənməni quraşdırın

## 📝 Versiya Tarixçəsi

- **v1.0.0** - İlk buraxılış
- **v1.1.4** - Timezone düzəlişləri, yenilənmə tarixi sistemi, stabillik təkmilləşdirmələri

## 👨‍💻 Müəllif

**DeXIV** - [dexiv.me](https://dexiv.me)

## 📄 Lisenziya

Bu layihə MIT lisenziyası altında buraxılmışdır. Ətraflı məlumat üçün [LICENSE](LICENSE) faylına baxın.

## 🤝 Töhfə vermək

1. Fork edin
2. Feature branch yaradın (`git checkout -b feature/AmazingFeature`)
3. Dəyişiklikləri commit edin (`git commit -m 'Add some AmazingFeature'`)
4. Branch-ı push edin (`git push origin feature/AmazingFeature`)
5. Pull Request açın

## 🔗 Əlaqə

- **Vebsayt**: [ai.dexiv.me](https://ai.dexiv.me)
- **DeXIV**: [dexiv.me](https://dexiv.me)
- **GitHub**: [XIV-AI-Feqih](https://github.com/MAkbarov/XIV-AI-Feqih)

---

*Son yenilənmə: 08.10.2025*