# CLR Calendar — one.com Deployment + MySQL Setup

This version includes:

- Single-admin login protection
- MySQL-backed reservations/history
- Dedicated **Reservations** page
- Dedicated **Admin Tools** page
- Full-width sticky header + optional uploaded logo
- Shared-hosting health check page

---

## 1) Login

- Username: `admin`
- Initial password: `Turkiet3040?!`

Login page:

- `https://yourdomain.com/clr-calendar/login.php`

After login:

- Calendar: `index.php`
- Admin tools: `admin_tools.php`
- Reservations: `reservations.php`
- Provider settings: `settings.php`
- Health check: `healthcheck.php`

---

## 2) New page structure

- `index.php` is now calendar-only (no admin forms there).
- `admin_tools.php` contains all management controls:
  - buildings/apartments
  - feed mapping
  - sync settings + sync now
  - change password
  - logo upload
  - quick link to reservation creation page
- `reservations.php` contains manual reservation form + reservation history list.

---

## 3) Upload logo for sticky header

In **Admin Tools** use **Upload header logo**.

Accepted formats:

- PNG
- JPG/JPEG
- WEBP
- SVG

Uploaded logo is stored under `data/uploads/` and shown in header on all pages.

---

## 4) Change admin password

In **Admin Tools** use **Change admin password**:

1. Enter current password.
2. Enter new password + confirmation.
3. Save.

---

## 5) one.com deployment quick steps

1. Upload project files to `public_html/clr-calendar/`.
2. Create MySQL database/user in one.com panel.
3. Import `database.sql`.
4. Create `data/db_config.php` from `data/db_config.php.example` and fill credentials.
5. Ensure `data/` is writable.
6. Open `healthcheck.php` and ensure required checks pass.

---

## 6) Responsive behavior

- Header and cards are mobile-friendly.
- Forms wrap on small screens.
- Only the scheduler grid itself scrolls horizontally when needed.

---

## 7) Security note

Initial password is your requested bootstrap password. Change it immediately after first login.

---

## 8) Local development quick run

From the project root, start PHP's built-in server:

```bash
php -S 0.0.0.0:8080 -t .
```

Then open:

- `http://localhost:8080/login.php`
