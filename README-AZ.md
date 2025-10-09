# XIV AI Chatbot Platform
**Versiya: 1.2.5**

## 🌟 Xüsusiyyətlər
- ✨ Azərbaycan dilində tamamilə hazırlanmış AI çatbot sistemi
- 🎨 Müasir və responsiv dizayn (TailwindCSS)
- 🔧 Admin paneli və təhlükəsizlik sistemi
- 📱 Mobil uyğunluq və PWA dəstəyi
- 🌐 Çoxdilli dəstək (Azərbaycan/İngilis)
- 🚀 Avtomatik yeniləmə sistemi
- 📊 İstifadəçi limitləri və statistika
- 💬 Bilik bazası inteqrasiyası
- 📧 E-poçt bildirişləri
- 🔒 İP təhlükəsizliyi və anti-spam

## 📋 Sistem Tələbləri
- PHP 8.1 və ya daha yeni versiya
- MySQL 5.7 və ya MariaDB 10.3+
- Apache/Nginx web serveri
- Composer (PHP paket meneceri)
- Node.js və npm (development üçün)

## 🛠 Quraşdırma
1. Faylları web server qovluğuna yükləyin
2. Brauzerinizdə `/install.php`-ə daxil olun
3. Quraşdırma sihirbazını tamamlayın:
   - Sistem tələblərini yoxlayın
   - Verilənlər bazası məlumatlarını daxil edin
   - Admin istifadəçi yaradın
   - E-poçt parametrlərini təyin edin (istəyə bağlı)

## ⚙️ Konfiqurasiya
Quraşdırmadan sonra `.env` faylında əlavə parametrlər:

```env
# GitHub Yeniləmə Sistemi
GITHUB_REPO_URL="https://api.github.com/repos/DeXIV/XIV-AI/releases"
GITHUB_VERSION_API="https://api.github.com/repos/DeXIV/XIV-AI/releases/latest"

# AI API Açarları (Admin paneldə də təyin edilə bilər)
OPENAI_API_KEY=your_openai_api_key
```

## 🔐 Təhlükəsizlik
- `.env` faylında həssas məlumatlar saxlanılır
- Admin panelə yalnız icazə verilmiş IP-lər daxil ola bilər
- İstifadəçi limitləri və anti-spam sistemi
- SQL injection və XSS qorunması

## 📞 Dəstək
- **Müəllif:** DeXIV
- **Version:** 1.2.5
- **Tarix:** 2025

## 📝 Lisenziya
Bu layihə şəxsi və kommersiya məqsədləri üçün istifadə edilə bilər.

---
**XIV AI - Azərbaycanın AI Çatbot Platforması** 🇦🇿