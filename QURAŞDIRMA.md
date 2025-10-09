# XIV AI - Quraşdırma Bələdçisi

## 🚀 Quraşdırma Addımları

### 1. Hazırlıq
- Web server qovluğuna (məs: `htdocs`, `www`) bütün faylları kopyalayın
- `.env.example` faylını `.env` olaraq kopyalamayın (quraşdırıcı bu faylı yaradacaq)

### 2. Avtomatik Quraşdırma
1. Brauzerinizdə `/install.php`-ə daxil olun
2. Quraşdırma sihirbazı açılacaq:
   - **Addım 1:** Sistem tələbləri yoxlanılacaq
   - **Addım 2:** Verilənlər bazası konfiqurasiyası
   - **Addım 3:** Admin istifadəçi və sayt parametrləri
   - **Addım 4:** Quraşdırmanın tamamlanması

### 3. XAMPP üçün Məsləhətlər
```
DB_HOST=localhost
DB_PORT=3306
DB_USERNAME=root
DB_PASSWORD="" (boş buraxın)
```

### 4. Sonrakı Addımlar
- Admin panelinə `/admin` vasitəsilə daxil olun
- AI API açarlarını təyin edin
- Sayt parametrlərini tənzimləyin

## ⚠️ Vacib Qeydlər

### Yenidən Quraşdırma
Əgər sistem artıq quraşdırılmışsa, installer avtomatik olaraq "artıq quraşdırılmış" səhifəsini göstərəcək. Bu, təkrar quraşdırma səhvlərinin qarşısını alır.

**Məcburi yenidən quraşdırma:**
- URL-ə `?force=1` əlavə edin
- Xəbərdarlıq səhifəsində "Yenə də davam et" düyməsini basın

### Təhlükəsizlik
- `.env` faylı avtomatik yaradılır və həssas məlumatları ehtiva edir
- `storage/installed.lock` faylı təkrar quraşdırmanın qarşısını alır
- Admin istifadəçi dublikat yaradılması səhvləri avtomatik həll olunur

### Problemlər
Əgər quraşdırma zamanı problem yaranarsa:
1. PHP error log-larını yoxlayın
2. Verilənlər bazası bağlantısını təsdiqləyin
3. Fayl icazələrini yoxlayın (`storage/` və `bootstrap/cache/` yazıla bilməlidir)

## 🔄 Yeniləmə Sistemi
Quraşdırmadan sonra admin paneldə avtomatik yeniləmə sistemi mövcuddur:
- GitHub repository-dən yenilənmələr yoxlanılır
- Avtomatik backup və yeniləmə prosesi

---
**XIV AI v1.2.5 - DeXIV tərəfindən** 🚀