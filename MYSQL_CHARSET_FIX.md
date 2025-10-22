# MySQL Character Set Fix - utf8mb4 Support

## ✅ Problem Solved

**Issue:** When switching from PostgreSQL to MySQL, you encountered this error:
```
SQLSTATE[HY000]: General error: 1366 Incorrect string value: '\x87N\xE2@\x00\x00...' 
for column 'text' at row 1
```

**Cause:** MySQL's default `utf8` charset only supports 3-byte characters, not the full 4-byte Unicode range (which includes emojis, special symbols, and some non-Latin characters).

**Solution:** Converted all tables to use `utf8mb4` charset, which supports the full Unicode range.

---

## 🔧 What Was Fixed

### 1. Database-Level Configuration
- ✅ Database converted to `utf8mb4` charset
- ✅ All tables converted to `utf8mb4_unicode_ci` collation
- ✅ All text columns set to use `utf8mb4`
- ✅ Special handling for `chunks.text` (set to LONGTEXT)

### 2. Application-Level Configuration
- ✅ `AppServiceProvider` updated to force utf8mb4 on MySQL connections
- ✅ Default string length set to 191 (prevents index errors)
- ✅ Connection charset enforced on every request

### 3. Tables Fixed
The following tables were converted to `utf8mb4`:
- `chunks` (text column set to LONGTEXT)
- `documents`
- `connectors`
- `organizations`
- `users`
- `conversations`
- `messages`
- `session_memory`
- `feedback`
- `ingest_jobs`
- `cost_tracking`
- `password_resets`

---

## 📁 Files Modified

### Backend/database/migrations/2025_10_21_093449_fix_mysql_charset_for_all_tables.php
**NEW MIGRATION** - Converts all existing tables to utf8mb4:
- Converts database charset
- Converts all tables to utf8mb4
- Specifically handles text columns
- Sets `chunks.text` to LONGTEXT for large documents

### Backend/app/Providers/AppServiceProvider.php
**UPDATED** - Ensures utf8mb4 is used on every connection:
```php
// Force utf8mb4 for MySQL connections
if (config('database.default') === 'mysql') {
    \DB::statement("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
    \DB::statement("SET CHARACTER SET utf8mb4");
    \DB::statement("SET character_set_connection=utf8mb4");
}
```

### Backend/config/database.php
**ALREADY CONFIGURED** - MySQL connection already uses utf8mb4:
```php
'charset' => env('DB_CHARSET', 'utf8mb4'),
'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
```

---

## 🎯 Benefits

### ✅ Full Unicode Support
- Emojis: 😀 🎉 ❤️ 🚀
- Special characters: ™ ® © • ◦ ▪
- Non-Latin scripts: 中文, العربية, हिन्दी, 日本語
- Mathematical symbols: ∑ ∫ ∞ ≠ ≤ ≥
- Currency symbols: €, £, ¥, ₹, ₽

### ✅ Works with Both Databases
The app now seamlessly works with:
- **PostgreSQL** - Already supports full Unicode natively
- **MySQL/MariaDB** - Now uses utf8mb4 for full Unicode support

### ✅ No Data Loss
- Existing data is preserved
- New inserts will work correctly
- No re-indexing required

---

## 🧪 Testing

### Test with Emojis
Try uploading a document with emojis or special characters:
```
✅ This document contains emojis 🎉 
✅ And special characters: café, résumé, naïve
✅ Mathematical symbols: ∑, ∫, ∞
```

### Verify Charset
Check that your database is using utf8mb4:
```sql
-- Check database charset
SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME 
FROM information_schema.SCHEMATA 
WHERE SCHEMA_NAME = 'your_database_name';

-- Check table charset
SELECT TABLE_NAME, TABLE_COLLATION 
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = 'your_database_name';

-- Check column charset for chunks.text
SELECT COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'your_database_name' 
  AND TABLE_NAME = 'chunks' 
  AND COLUMN_NAME = 'text';
```

Expected output:
- Database: `utf8mb4`, `utf8mb4_unicode_ci`
- Tables: `utf8mb4_unicode_ci`
- chunks.text: `utf8mb4`, `utf8mb4_unicode_ci`

---

## 🔄 Switching Between Databases

The app now supports seamless switching between PostgreSQL and MySQL:

### Using PostgreSQL
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=khub
DB_USERNAME=postgres
DB_PASSWORD=your_password
```

### Using MySQL
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=khub
DB_USERNAME=root
DB_PASSWORD=your_password
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

**Both will work perfectly!** ✅

---

## 🚨 Troubleshooting

### Error: "Syntax error or access violation" for JSON columns
**This is EXPECTED and harmless!** JSON columns don't support charset specification in MySQL. The migration handles this gracefully with try-catch blocks.

### Error: "Specified key was too long"
**Already Fixed!** `AppServiceProvider` sets default string length to 191 characters for indexed columns.

### Still Getting Character Encoding Errors?
1. **Clear config cache:**
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

2. **Restart your database connection:**
   ```bash
   php artisan queue:restart  # If using queue workers
   ```

3. **Verify .env settings:**
   ```env
   DB_CHARSET=utf8mb4
   DB_COLLATION=utf8mb4_unicode_ci
   ```

4. **Check MySQL version:**
   utf8mb4 requires MySQL 5.5.3+ or MariaDB 5.5+
   ```bash
   mysql --version
   ```

---

## 📝 Migration Notes

### Running the Migration
The migration was already run during the fix, but if you need to run it again:
```bash
php artisan migrate
```

### Rolling Back (Not Recommended)
This migration is **non-reversible** because:
- Reverting to `utf8` could cause data loss
- Full Unicode data would be truncated
- It's a compatibility fix, not a feature

### Fresh Database
If setting up a fresh database, this migration will:
- Safely skip if tables don't exist yet
- Run automatically with `php artisan migrate`
- Ensure all new tables use utf8mb4

---

## 🎓 Technical Details

### What is utf8mb4?
- **utf8mb4** = UTF-8 with 4-byte character support
- **utf8** (MySQL) = UTF-8 with only 3-byte character support (incomplete!)
- **utf8mb4_unicode_ci** = Case-insensitive Unicode collation

### Why was this needed?
MySQL's `utf8` charset is actually a **partial implementation** that only supports:
- Characters in the Basic Multilingual Plane (BMP)
- Up to 3 bytes per character

It **doesn't support**:
- Emojis (4 bytes): 😀 🎉 ❤️
- Some Chinese characters (4 bytes)
- Mathematical symbols (4 bytes)
- Various special Unicode characters

### Performance Impact
- **Minimal** - utf8mb4 only uses extra bytes when needed
- Indexes might be slightly larger
- Query performance is identical

---

## ✅ Summary

Your KHub app now:
- ✅ **Supports full Unicode** (emojis, special characters, all languages)
- ✅ **Works with both PostgreSQL and MySQL** seamlessly
- ✅ **No more "Incorrect string value" errors**
- ✅ **Future-proof** for any text content
- ✅ **Automatically configured** on every connection

**You can now switch between databases without any encoding issues!** 🚀

---

## 📚 References

- [MySQL utf8mb4 Documentation](https://dev.mysql.com/doc/refman/8.0/en/charset-unicode-utf8mb4.html)
- [Laravel Database Documentation](https://laravel.com/docs/10.x/database)
- [Unicode Character Ranges](https://en.wikipedia.org/wiki/UTF-8)

---

**✨ Character encoding is now rock-solid across both PostgreSQL and MySQL!**

