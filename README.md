# CLR Calendar — one.com Deployment + MySQL Setup

This version includes:

- **Single-admin login protection**
- **MySQL-backed reservation storage** (recommended)
- **Health check page** for shared-hosting diagnostics

---

## 1) Login credentials

Admin pages now require login.

- Username: `admin`
- Password: `Turkiet3040?!`

Login page:

- `https://yourdomain.com/clr-calendar/login.php`

After login, you can access:

- `index.php`
- `admin_tools.php`
- `settings.php`
- `healthcheck.php`

---

## 2) What is stored where?

### MySQL (recommended / production)

- Manual reservations/bookings with full guest details
- Imported iCal reservations
- Pricing/payment details
- Reservation event history (audit trail)

### JSON in `data/` (supporting/fallback)

- Buildings/apartments structure
- Sync settings and metadata
- Reservation fallback cache if MySQL is not configured

---

## 3) Files to upload

Upload into `public_html/clr-calendar/` (or your chosen folder):

- `index.php`
- `login.php`
- `logout.php`
- `admin_tools.php`
- `settings.php`
- `healthcheck.php`
- `db.php`
- `database.sql`
- `assets/` folder
- `data/` folder (must exist)
- `data/db_config.php.example`

---

## 4) Step-by-step install on one.com

### Step 1 — Upload files

Upload all files via one.com File Manager or FTP.

### Step 2 — Create MySQL DB

From one.com panel create database/user/password and note:

- host
- port (usually 3306)
- db name
- username
- password

### Step 3 — Import schema

Import `database.sql` into the MySQL database.

### Step 4 — Configure DB credentials

Create `data/db_config.php` from `data/db_config.php.example`:

```php
<?php
return [
  'host' => 'your-db-host',
  'port' => 3306,
  'name' => 'your_db_name',
  'user' => 'your_db_user',
  'pass' => 'your_db_password',
  'charset' => 'utf8mb4',
];
```

### Step 5 — Ensure writable `data/`

Set `data/` writable (755/775 depending on one.com environment).

### Step 6 — Run health check

Open:

- `https://yourdomain.com/clr-calendar/healthcheck.php`

Confirm PASS for:

- PHP version
- PDO + pdo_mysql
- data folder writable
- MySQL connection
- required tables (`reservations`, `reservation_events`)

### Step 7 — Use app

- Main board: `index.php`
- Admin Tools: `admin_tools.php`
- Provider settings: `settings.php`

---

## 5) Change admin password

1. Login and open `admin_tools.php`.
2. Use **Change admin password** card.
3. Enter current password and new password twice.
4. Save, then login again with the new password.

---

## 6) Manual booking with full details

In Admin Tools, use **Manual reservation / booking (full details)** to store:

- check-in/out dates
- customer identity/contact
- occupancy
- price/currency/taxes/fees/discount
- payment method/status
- booking channel
- notes

These are saved in MySQL and shown in reservation history.

---

## 7) Troubleshooting

### “MySQL not configured” warning

- Verify `data/db_config.php` exists.
- Verify credentials.
- Verify `database.sql` imported.
- Run `healthcheck.php`.

### Can’t login

- Confirm exact credentials above.
- Make sure sessions/cookies are enabled in browser.

### iCal not importing

- Re-check Airbnb/Booking iCal URLs.
- Use Sync Now and review status messages.

---

## 8) Security note

You requested hardcoded single-admin credentials. In production, rotate this password and move secrets to environment/config outside web root when possible.
