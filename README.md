## Live Demo

- https://kazewallet.free.nf

## Requirements

- PHP 8+
- MySQL/MariaDB

## Local Setup (XAMPP)

1. Start **Apache** and **MySQL** in XAMPP.
2. Import the database file:
   - phpMyAdmin → Import → select `database.sql` → Go
3. Copy config:
   - Copy `config/config.local.example.php` to `config/config.local.php`
   - Update DB credentials in `config/config.local.php` if needed
4. Run migration (optional, if you already imported `database.sql` you can skip this):

   ```bash
   php scripts/migrate.php
   ```

5. Run the app:

   ```bash
   php -S localhost:8000 -t public
   ```

6. Open:
   - http://localhost:8000/

## Admin Account

Admin account:

- Email: `admin@ewallet.local`
- Password: `admin123`

## Email / OTP Notes

- All generated credentials and OTP codes are written to `storage/mail_outbox.log`.

## Database Files Included

- `database.sql` (creates database + tables + seeds admin; importable)
- `database/schema.sql` (tables only)
- `scripts/migrate.php` (CLI migration + seeds admin)

## Hosting Setup (InfinityFree)

Upload the whole project into `htdocs/`.

Config files:

- Local machine: copy `config/config.local.example.php` to `config/config.local.php`
- WEb Hosting: copy `config/config.hosting.example.php` to `config/config.hosting.php`

DB install on hosting:

- Set DB credentials in `config/config.hosting.php`
- Set `app.install_token` in `config/config.hosting.php`
- Open: `https://your-domain/install.php?token=YOUR_TOKEN`
- After installing, delete `public/install.php` (recommended)
