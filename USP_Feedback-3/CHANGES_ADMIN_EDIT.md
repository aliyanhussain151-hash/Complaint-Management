# Admin "edit everything" changes

## New file
- `includes/admin_helpers.php` – CSRF token helpers, prepared-statement `admin_log()`, account validation, `reply_exists()`.

## Changed files
| File | Change |
|---|---|
| `admin/manage_users.php` | **Edit** button + modal (name, email, status, optional new password). Action `edit_user`. Only touches `role = 'user'` rows. |
| `admin/manage_staff.php` | **Edit** button + modal (same fields). Action `edit_staff`. Flash messages after save. Toggle-status now restricted to `role = 'staff'` (previously any id, including admins). |
| `admin/manage_complaints.php` | **Edit** button + modal (subject, category, message, status, assigned staff, solution). Action `edit_complaint`. Reply edit/delete handlers (`edit_reply`, `delete_reply`). Quick "Status" action no longer stamps `resolved_at` on non-Resolved statuses. |
| `admin/get_complaint_detail.php` | Each reply now has Edit (inline textarea) and Delete buttons. |

## Behaviour notes
- All new actions use prepared statements, a CSRF token, and write to `admin_logs`
  (`EDIT_USER`, `EDIT_STAFF`, `EDIT_COMPLAINT`, `EDIT_REPLY`, `DELETE_REPLY`).
- Leaving the password blank keeps the current password.
- `resolved_at` is set when a complaint becomes Resolved, kept if it already was, and cleared otherwise.
- No database schema changes.
- Existing (old) actions such as delete / reset password / assign were left as they were and do not yet send a CSRF token.

---

# Zoom / responsive layout fix

**Problem:** every page used a fixed 250px sidebar with a matching left margin. Browser zoom shrinks the CSS
viewport (200% on a 1440px screen = 720px, 400% = 360px), so at 200% the admin sidebar covered the whole screen
and at 400% the user/staff pages had ~110px left for content.

**Fix (no change to desktop appearance at 100%):**
- New `css/responsive.css` + `js/responsive.js`, linked into all 26 full pages (viewport meta tag added where missing).
- Below 992px (≈150% zoom and up, and phones/tablets) the sidebar becomes a slide-in drawer opened with a
  hamburger button (closes via backdrop, Esc, or choosing a link) and content uses the full width.
- Tables get their own horizontal scroll box instead of stretching the page; long text wraps; images/canvases are capped to their container.
- The admin login panel's off-screen slide-in animation is disabled on narrow screens (it caused a scrollbar flash).
- The menu button hides while a modal is open.

**Tested** in headless Chromium: 20 pages x zoom 100/150/200/300/400% -> 0 horizontal overflow (was 15 problem cases);
drawer, edit modals and reply controls checked at 200-400%; 100% desktop renders were compared pixel-by-pixel with the originals.

---

# Admin login + small-laptop screens / zoom-out

## Admin login (`admin/admin_login.php`)
Tested end to end against the supplied database: correct login, wrong password, unknown email, non-admin account,
deactivated admin, access without login, logout. All behave correctly. Improvements:
- Every attempt (success and failure) is now recorded in `admin_login_attempts`, so the "login history" box on the
  admin profile page finally shows data (it was always empty because nothing wrote to that table).
- `session_regenerate_id()` after a successful login; the email lookup now uses a prepared statement.
- **`admin@usp.com` (the README's `admin123`) could never log in** - the dump contains a placeholder hash for it
  (and for `staff@usp.com`). New command-line tool to set a real password:
  `php tools/set_password.php admin@usp.com "YourNewPassword"`  (CLI only; returns 404 through the web server).
  The two other admins in the dump (`admin@usp2.com`, `waseemliaqat136@outlook.com`) have real hashes - use their own passwords.

## Screen fit (`js/screen-fit.js`, `css/responsive.css`)
- Laptop-sized screens (1100-1919px wide, mouse/trackpad) get a gentle overall size reduction so the site looks the way it
  does at ~67-80% on a big monitor, without the user touching browser zoom:
  1366px screen -> 0.71, 1440 -> 0.75, 1536 -> 0.80, 1600 -> 0.83, never below 0.70. 1920px+ monitors, phones and tablets are unchanged.
- Based on the screen size (which Chrome keeps constant), so Ctrl +/- still works on top: zoom in -> bigger and the
  layout reflows (menu becomes a drawer); zoom out -> smaller and content stays centred, max 1700px wide.
- Per-visitor switch: add `?fit=off` to any URL (remembered) or `?fit=on`. Tune for everybody: edit the three constants at the top of `js/screen-fit.js`.
- Also fixed: the admin top bar was pushed right twice (blank gap above the content); modal backdrops and full-height
  sidebars are compensated for the scaling.
- Tested in Chromium on 190 page/size combinations (1280x720, 1366x768, 1440x900, 1536x864, 1920x1080 at 67-200% zoom) and
  160 page/zoom combinations on a simulated 1366x768 laptop: no horizontal overflow, sidebar reaches the bottom, modals cover the screen.
