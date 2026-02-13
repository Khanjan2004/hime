# Vocabulary Platform (PHP)

`index.php` va `config.php` fayllarini hostingga yuklang.

## Storage
- Asosiy rejim: SQLite (`storage.sqlite`).
- Agar hostingda `pdo_sqlite` yoqilmagan bo‘lsa, tizim avtomatik `storage.json` ga o‘tadi (500 xatosiz ishlaydi).

## Agar 500 chiqsa
- `pdo_sqlite` modulini yoqing **yoki** JSON fallbackdan foydalaning.
- Papkaga yozish huquqini tekshiring (`storage.sqlite` / `storage.json` yaratila olishi kerak).
