# USP Complaint & Feedback Management System

A PHP + MySQL web app for managing complaints and feedback, with three portals:
**User** (submit/track complaints), **Staff** (resolve assigned complaints), and
**Admin** (manage everything, staff, users, reports).

---

## Requirements

- PHP 8.x (with the `mysqli` extension, included by default)
- MySQL 5.7+ or MariaDB
- A web browser

You can use either:

- **XAMPP / MAMP / WAMP** (easiest on Windows), or
- **PHP and MySQL installed directly** (e.g. Homebrew, see below).

> Apple Silicon note: the bundled XAMPP PHP is x86-only and may fail with
> `bad CPU type in executable`. In that case install PHP with Homebrew
> (`brew install php`), which is what the Homebrew instructions below use.

---

## Windows — one-click setup (recommended for non-technical users)

If you are on Windows and just want it running, do this:

1. Install **XAMPP** to the default folder `C:\xampp`:
   <https://www.apachefriends.org/download.html>
   (Only XAMPP is needed — do **not** start Apache/MySQL manually.)
2. **Double-click `START.bat`** in this folder.

That's it. `START.bat` starts MySQL, loads the database, launches the website,
and opens your browser at <http://localhost:8000>. Close the black
"USP Server" window to stop the site.

Logins: `admin@usp.com` / `admin123`, `staff@usp.com` / `staff123`,
`user@usp.com` / `user1234`.

> If Windows shows a firewall prompt about PHP, click **Allow access**.
> If you installed XAMPP somewhere other than `C:\xampp`, open `START.bat` in
> Notepad and change the `set "XAMPP=..."` line.

The manual steps below are for macOS / Linux or for anyone who prefers to set
things up by hand.

---

## Setup

### 1. Start MySQL

Make sure your MySQL/MariaDB server is running and you know the **root password**.

- **XAMPP/MAMP**: start MySQL from the control panel.
- **Homebrew (macOS)**:
  ```bash
  brew services start mysql      # or: brew services start mariadb
  ```

### 2. Create the database and import the schema

**Easy path:** import the single self-contained dump — it creates the `USP`
database, all tables, sample complaints, **and working login accounts** (so you
can skip step 4):

```bash
mysql -u root -p < database/usp_custom.sql
```

Then jump straight to step 3 (configure) and 5 (run).

**Manual path (individual files):** import `database/USP.sql` first — it
creates the `USP` database and all core tables:

```bash
mysql -u root -p < database/USP.sql
```

Then import the three optional tables (login history, activity logs, admin
security tables). These files **do not** contain a `USE USP;` line, so pass the
database name explicitly:

```bash
mysql -u root -p USP < database/database_create_login_tables_Version3.sql
mysql -u root -p USP < database/database_activity_logs_Version2.sql
mysql -u root -p USP < database/database_USP_admin_special_Version2.sql
```

Using phpMyAdmin instead? Select the `USP` database first, then import each file
in the same order.

> The last file may report `Duplicate entry 'admin@usp.com'` — that is harmless
> (the admin row already exists from `USP.sql`).

### 3. Configure the database connection

`config/db_connect.php` **auto-detects** the password, so on a normal setup
(XAMPP with no root password, or Homebrew with `root`/`root`) you don't need to
edit anything. It tries, in order: the `DB_PASSWORD` value, then the common
defaults (empty, `root`, `password`).

If your server uses something else, set it explicitly — either edit the
`DB_USER` / `DB_NAME` lines, or set an environment variable `DB_PASSWORD`.
It connects to server `localhost`, user `root`, database `USP` by default.

### 4. Set real passwords for the sample accounts (important)

The sample rows in `USP.sql` contain **placeholder hashes**, so nobody can log in
with them until you set a real password. Use the included CLI helper (password
must be **at least 8 characters**):

```bash
php tools/set_password.php admin@usp.com admin123
php tools/set_password.php staff@usp.com staff123
php tools/set_password.php user@usp.com  user1234
```

This also sets each account to `active`. The tool refuses to run through the web
server (it returns 404) — it is terminal-only by design.

### 5. Run the site

**Option A — PHP built-in server (no Apache needed):**

```bash
php -S localhost:8000
```

Then open <http://localhost:8000>.

**Option B — Apache (XAMPP/MAMP):**

Copy the project folder into your web root (`htdocs` / `www`), start Apache, then
open <http://localhost/USP_Feedback-3/>.

---

## Logins

| Portal    | URL                          | Email            | Password   |
|-----------|------------------------------|------------------|------------|
| Admin     | `/admin/admin_login.php`     | `admin@usp.com`  | `admin123` |
| Staff     | `/staff/staff_login.php`     | `staff@usp.com`  | `staff123` |
| User      | `/user/login.php`            | `user@usp.com`   | `user1234` |

You can also register a new user from `/user/register.php`.

> Change these before any real use. They are demo credentials only.

---

## Troubleshooting

- **"Access denied for user 'root'@'localhost'"** — your MySQL password is not
  one of the auto-detected defaults (empty / `root` / `password`). Set the
  `DB_PASSWORD` environment variable or edit `config/db_connect.php`.
- **"No database selected" (error 1046) when importing** — you imported one of
  the extra SQL files without selecting `USP`. Use `mysql -u root -p USP < file`.
- **Admin/staff login always fails with "Invalid password"** — you skipped step 4.
  The sample hashes are placeholders; run `tools/set_password.php`.
- **Login button spins forever after pressing Enter** — was fixed in
  `admin/admin_login.php` (a redundant Enter-key handler blocked native submit).
  If you copied an older version, remove that keypress handler.
- **XAMPP PHP: "bad CPU type in executable" (Apple Silicon)** — install PHP via
  Homebrew (`brew install php`) and run with the built-in server.

---

## Project structure

```
START.bat   Windows one-click launcher (double-click to run)
admin/      admin portal (dashboard, manage users/staff/complaints, reports)
staff/      staff portal (assigned complaints, resolve, reply)
user/       user portal (register, login, submit/track complaints)
config/     db_connect.php (database configuration, auto-detects password)
database/   SQL schema and seed data (usp_custom.sql = full ready-to-use dump)
includes/   shared helpers, navbar, sidebar, footer
css/ js/    styles and scripts (responsive layout, screen-fit)
tools/      set_password.php CLI helper
```
