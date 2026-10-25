# CHANGES: Complaint Evidence, Closure Notifications, Status Timeline

Three enhancements to the USP Complaint Management System, verified end to end
against a running server. No unrelated refactors; existing architecture, naming
and page layout reused throughout.

---

## 1. Complaint Evidence (file upload)

Students can attach evidence when submitting a complaint, and add more later.
Admins and the assigned staff member can view the files.

### Files

| File | Change |
|---|---|
| `includes/common_helpers.php` | New. Shared helpers: CSRF, evidence validation/storage, timeline, notifications. |
| `user/submit_complaint.php` | Evidence section added to the submit form; transactional insert of complaint + attachments. |
| `user/complaint_status.php` | Evidence list and "add more evidence" form on the detail view (`?id=N`). |
| `user/view_attachment.php` | New. Authenticated download endpoint with ownership/role checks. |
| `database/migration_001_evidence_notifications_timeline.sql` | `complaint_attachments` table. |
| `tools/migrate.php` | New. Idempotent migration runner (`--dry-run`, `--database=`). |

### Security controls (all server-side)

- **Extension allow-list**: jpg, jpeg, png, pdf only. Any other extension is
  rejected before the file is read.
- **Declared MIME type**: must be one of the allowed image/pdf types.
- **Magic bytes**: the real file content is sniffed with `finfo`; a PHP script
  renamed to `.png` is rejected because its bytes are not a PNG.
- **Extension/content cross-check**: extension, declared MIME and sniffed bytes
  must all agree.
- **Double extension block**: `shell.php.jpg` is rejected.
- **Path traversal**: `../../x.png`, absolute paths and null bytes are rejected.
- **Size**: 5 MB per file, 5 files per complaint, 20 MB total.
- **Random server-side names**: files are stored as `<32 hex>.<ext>`,
  never derived from user input. The original name is sanitised and kept as
  metadata only.
- **Storage outside the document root**: `<project>/../storage/complaint_evidence`,
  overridable via the `EVIDENCE_STORAGE_DIR` environment variable. A deny-all
  `.htaccess` is written for defence in depth on Apache deployments.
- **No orphans**: complaint row + attachment rows + moved files are committed
  together. On any failure the transaction rolls back and already-moved files
  are deleted.

### Verified

| Case | Result |
|---|---|
| Valid PNG upload | Stored, row created, file on disk with random name |
| PHP script renamed to `.png` | Rejected: "content is not a JPG, PNG or PDF" |
| `.php` file | Rejected: "Only JPG, JPEG, PNG and PDF files are allowed" |
| Owner views own file | 200, correct bytes, `X-Content-Type-Options: nosniff` |
| Admin views file | 200 |
| Assigned staff views file | 200 |
| Unrelated user / anonymous | 403 |
| Non-existent attachment | 404 |

---

## 2. Closure Notifications

**Root cause of the broken message.** There was no path by which a student
could learn a complaint had been closed. Concretely:

- `admin/manage_complaints.php` handled a generic `update_status` action that
  wrote `complaints.status` directly and nothing else. No notification row, no
  timeline row, no user-facing read path.
- No notification system existed at all — there was no `notifications` table
  and no page that displayed anything of the kind, so even a manual status
  change could not reach the student.
- `staff/resolve_complaint.php` had the same gap: it updated status and
  solution and returned "Updated successfully!" to the staff member only.
- Schema drift made it worse: the code referenced `replies.is_read` and
  `replies.receiver_id`, neither of which existed in the live schema, so
  related read-state updates failed with `ERROR 1054`.

### Fix

A real **Close Complaint** action on `admin/manage_complaints.php` that, inside
**one transaction**:

1. Locks and re-reads the complaint (`SELECT ... FOR UPDATE`).
2. Rejects the close if the complaint is already `Resolved` (idempotent — a
   retry or double-click cannot produce a second notification).
3. Sets `status = 'Resolved'`, `resolved_at = NOW()`, `solution = <text>`.
4. Inserts the timeline event.
5. Inserts the in-app notification.

If any step fails the transaction rolls back and the complaint is left
unchanged. The Close button disables itself while the request is in flight.
Resolution text is mandatory; an empty resolution is refused. CSRF is
required.

Message format (exact):

```
Your complaint #<ID> has been closed. Resolution: <resolution details.>
```

`staff/resolve_complaint.php` was updated to the same standard: when staff set
a complaint to `Resolved` it now commits status + solution + timeline +
notification together.

Notification delivery is **in-app only** — a `notifications` table plus a
panel on the student dashboard with an unread badge and "mark all read". No
email, SMS or third-party service was introduced.

### Files

| File | Change |
|---|---|
| `admin/manage_complaints.php` | New `close_complaint` action (CSRF, status guard, transaction). `edit_complaint` now records a `status_changed` timeline event when the edit changes the status. |
| `staff/resolve_complaint.php` | Resolving now notifies the student in the same transaction; fixed broken `replies.is_read` / `receiver_id` writes. |
| `user/index.php` | Notifications panel with unread badge and mark-read. |
| `user/mark_notifications_read.php` | New. Marks the session user's notifications read. |
| `database/migration_001_evidence_notifications_timeline.sql` | `notifications` table with a dedupe unique key. |

### Verified

| Case | Result |
|---|---|
| Admin closes a Pending complaint | Status `Resolved`, `resolved_at` set, exactly one notification, one timeline row |
| Double close (retry) | Second attempt rejected; still exactly one notification |
| Close with empty resolution | Refused, complaint unchanged |
| Close with bad CSRF token | Refused, complaint unchanged |
| Staff resolves an assigned complaint | Same transactional result, actor recorded as staff |
| Student dashboard | Notification visible with the exact message; unread badge correct |
| Mark all read | `is_read` set to 1 |

---

## 3. Status Timeline

Each complaint now has an ordered, user-visible history of what happened and
when, stored in `complaint_status_history` and written in the **same
transaction** as the change it describes.

### Events recorded

`submitted`, `assigned`, `reassigned`, `unassigned`, `status_changed`, `closed`

### Honesty rules

- Timestamps come from the database clock at write time. Nothing is fabricated.
- A failure to write the timeline row rolls back the change it describes, so
  the history can never silently disagree with reality.
- `Resolved` and closed are distinguishable: the `event_type` distinguishes a
  generic status change from an actual closure.

### Display

- Student: `user/complaint_status.php?id=N` — a semantic `<ol>` timeline,
  ownership verified on every request (non-owners get 404, not a leak).
- Admin: status-history block inside `admin/get_complaint_detail.php`.

---

## Schema Changes

Three new tables, all created idempotently with `IF NOT EXISTS`:

- `complaint_attachments`
- `notifications` (unique key on `(complaint_id, type, user_id)` for idempotency)
- `complaint_status_history`

Plus repaired drift on `replies`: `is_read` and `receiver_id` were referenced
by existing code but missing from the live schema.

### Migration

```bash
# inspect what would run, without touching the DB
php tools/migrate.php --dry-run

# apply
php tools/migrate.php

# safe to re-run; reports "no changes" when up to date
php tools/migrate.php

# against a different database
php tools/migrate.php --database=usp_dryrun
```

**Backup taken before any schema change:**
`C:\Users\aliya\Documents\Default Project\USP_Feedback-3\backups\usp_backup_20261018_214048.sql`

**Rollback:** restore the backup above (`mysql usp < <backup file>`). The
migration only adds tables and columns; it drops nothing and changes no
existing column.

The migration was dry-run against a copy first, then applied to live. Row
counts for `users`, `complaints` and `replies` were unchanged after apply.

---

## Tests

```bash
# with the app served, e.g.:  php -S localhost:8000 -t USP_Feedback-3
php tools/run_tests.php --base-url=http://localhost:8000

# individual layers can be skipped
php tools/run_tests.php --skip-http --skip-migration
```

`tools/run_tests.php` covers syntax on all touched files, migration
idempotency (runs it twice and requires the second to be a no-op) and real
HTTP assertions for happy paths, role separation and evidence access control.
It only reads pages and cleans up anything it creates; exit code 0 = pass.

**Result: 41 passed, 0 failed.**

---

## Install / Run

```bash
# 1. restore or create the database
mysql -u root < database/usp_custom.sql      # or restore the backup

# 2. apply the migration
php tools/migrate.php

# 3. serve (document root is the folder containing index.php)
php -S localhost:8000 -t USP_Feedback-3

# 4. run the tests
php tools/run_tests.php --base-url=http://localhost:8000
```

Evidence files are written to `<project>/../storage/complaint_evidence`. Set
`EVIDENCE_STORAGE_DIR` to relocate it; the directory is created on first use.

Test accounts: `user@usp.com/user1234`, `admin@usp.com/admin123`,
`staff@usp.com/staff123`.

---

## Critical Fix: `START.bat` destroyed the database on every launch

**Symptom.** After restarting the site with `START.bat`, all three new tables
(`complaint_attachments`, `notifications`, `complaint_status_history`) and the
added `replies` columns were gone, and complaint data had reverted to the seed
rows.

**Root cause (file/line).** `START.bat` step `[2/3] Loading the database...`
ran `mysql -u root < database\usp_custom.sql` **unconditionally on every
launch**. `usp_custom.sql` starts with `DROP TABLE IF EXISTS` for every table,
so each start wiped the live database and reloaded seed data — silently
destroying all data and every schema change.

**Fix.** `START.bat` now checks `SHOW DATABASES LIKE 'usp'` first:
- database **missing** → import `usp_custom.sql` once, then automatically run
  `php tools/migrate.php` so a fresh install is fully migrated;
- database **exists** → left completely untouched.

**Recovery performed:** the pre-change backup
(`backups/usp_backup_20261018_214048.sql`) was restored, `tools/migrate.php`
re-applied (3 tables + 2 columns + 3 honest `submitted` backfill rows), and the
full test suite re-run: **39/39 PASS** (two header assertions skip while no
attachment fixture exists). The closure flow was re-verified on the restored
database: status `Resolved`, exact-format notification, `closed` timeline event.

---

## Editing Functions — Regression Verification

Every editing path in the system was exercised against the running server
(changes were made and reverted; final DB state matches the starting state):

| Edit function | Result |
|---|---|
| Admin: edit complaint (subject/category/message/status/solution) | ✅ saved; `resolved_at` correctly cleared when status left Resolved |
| Admin: edit complaint with invalid status value | ✅ rejected, row unchanged |
| Admin: edit complaint with bad CSRF token | ✅ rejected, row unchanged |
| Admin: generic status update (Status button) | ✅ applied + timeline event |
| Admin: close complaint (incl. double-close) | ✅ transactional, exactly one notification |
| Admin: edit reply / delete reply | ✅ applied / removed; bad CSRF rejected |
| Admin: edit staff record (name/email/status) | ✅ saved |
| Admin: toggle staff status | ✅ toggled |
| Admin: edit user record | ✅ saved |
| Admin: own profile name/email edit | ✅ saved |
| Admin: password change with wrong current password | ✅ rejected |
| Admin/staff/user: assign / reassign / remove assignment | ✅ applied, each with the correct timeline event |
| Staff: add reply to assigned complaint | ✅ inserted (previously broken `receiver_id`/`is_read` now populated) |
| Staff: status update (non-Resolved) | ✅ applied + timeline event |
| Staff: resolve (→ Resolved) | ✅ status + solution + timeline + notification in one transaction |
| Staff: own profile edit / password change (wrong current) | ✅ saved / rejected |
| User: profile name/email edit | ✅ saved |
| User: password change (wrong vs correct current password) | ✅ rejected / applied |
| User: add evidence to own complaint | ✅ transactional, rollback verified |

One gap was found and fixed during this pass: `edit_complaint` changed
complaint status without writing a timeline event; it now records
`status_changed` (verified in the DB after the fix).

---

## Known Limitations

- **Notification delivery is in-app only.** There is no email or SMS; a
  student who does not open the dashboard will not see the closure notice.
- **Deleting a complaint does not delete its stored files.** The existing
  Delete action cascades the `complaint_attachments` rows (DB FK) but nothing
  removes the physical files, leaving them orphaned on disk. Orphaned files
  are unreachable through `view_attachment.php` (which requires a DB row), so
  this is a disk-space leak, not a security hole. A cleanup was out of scope
  here; a periodic sweep of files with no matching row would fix it.
- **No antivirus scanning** of uploaded evidence. Files are validated by
  extension, MIME type and magic bytes, but not scanned for malware.
- **Evidence is retained after closure.** Closing or deleting a complaint
  cascades attachment rows (and their files on delete), but a closed
  complaint keeps its evidence — there is no expiry or purge policy.
- **Upload limit is bounded by PHP itself.** The 5 MB / 20 MB application
  limits sit below `upload_max_filesize`; raising the application limit above
  the PHP limit will not help.
- **Concurrent edits are not locked globally.** The close action locks its own
  row with `SELECT ... FOR UPDATE`; other admin edit paths still use plain
  reads and can last-write-win.

---

## Fix: Staff dashboard had no evidence display

**Symptom.** Evidence attached to a complaint was visible in the Admin
dashboard (`admin/get_complaint_detail.php`) and the User dashboard
(`user/complaint_status.php?id=N`), but the Staff area showed nothing about
attachments and offered no way to open them.

**Root cause.** Frontend/feature gap only — no permission or schema problem:

- `staff/resolve_complaint.php` (the complaint detail page staff open from
  the dashboard's *Message* button) queried `complaints` and `replies` but
  **never queried `complaint_attachments`** and rendered no evidence section.
- `staff/staff_dashboard.php` never counted or displayed attachments either.
- The secure download endpoint `user/view_attachment.php` already authorised
  the assigned staff member (`$is_assigned_staff`) all along, so the server
  side would have served the files correctly the moment a link existed.

### Fix (2 pages + 1 endpoint option, no schema change)

| File | Change |
|---|---|
| `staff/resolve_complaint.php` | Loads the complaint's `complaint_attachments` rows (prepared statement, gated by the shared `can_view_evidence()` helper after the assignment-verified fetch) and renders an **Evidence Files** box: filename, size, MIME type, **View Evidence** (inline open) and **Download** buttons, or *"No evidence attached."* |
| `staff/staff_dashboard.php` | New **Evidence** column: per-complaint attachment counts (single grouped query filtered to `assigned_staff = <this staff>`), each linking to the complaint's detail page. |
| `user/view_attachment.php` | Optional `&download=1` sets `Content-Disposition: attachment` instead of `inline`. Same endpoint, same owner/admin/assigned-staff checks — the default `inline` behaviour used by Admin and User is unchanged. |
| `tools/run_tests.php` | +`staff/staff_dashboard.php` syntax check, an `http_body()` helper, self-cleaning fixtures (removed on shutdown) and 20 new assertions for the staff UI and the evidence access matrix. |

Security is unchanged and enforced server-side on every request: the staff
detail page only loads complaints with `assigned_staff = <session staff>`,
the attachment list is gated by `can_view_evidence()`, and every
View/Download link re-validates owner/admin/assigned-staff in
`view_attachment.php` (403 otherwise; 404 for missing rows or missing files).
Storage stays outside the document root. No tables, columns or records were
added — the `complaint_attachments` table is reused as is.

### Verified (`php tools/run_tests.php` → **61 passed, 0 failed**)

| Case | Result |
|---|---|
| Staff dashboard shows the Evidence column + action | ✅ |
| Staff detail page lists attachments with name/size/type, View + Download | ✅ |
| Multi-file complaint lists every attachment | ✅ |
| Complaint without evidence → "No evidence attached." | ✅ |
| Staff opens a real image inline (200, `image/png`, PNG bytes) | ✅ |
| `&download=1` → `Content-Disposition: attachment` | ✅ |
| Admin and User evidence views unchanged | ✅ |
| Staff denied an attachment of an unassigned complaint (403) | ✅ |
| Staff detail page hides unassigned complaints | ✅ |
| Another user / anonymous denied foreign evidence (403) | ✅ |
| Broken/missing file reference → clean 404 | ✅ |

Test fixtures (one temporary complaint + attachment, one copied file) are
created before the assertions and deleted on shutdown; complaints (5) and
attachments (2) row counts are identical before and after every run.
