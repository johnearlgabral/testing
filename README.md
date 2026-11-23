# UBMSConnect
### Real-Time Activity Monitoring Dashboard for UBMS Staff Management

A web-based monitoring and status-tracking system for the University Building and
Maintenance Service (UBMS), built with **PHP + MySQL + Bootstrap-free vanilla
HTML/CSS/JS**, designed to run on **XAMPP**.

This system is a **monitoring dashboard**, not an event-creation/booking tool.
Activities, venues, and utilities are pre-existing records that UBMS Staff
monitor, update the status of, and log maintenance notes against.

---

## 1. Requirements

- Windows 10/11 (or any OS running XAMPP)
- [XAMPP](https://www.apachefriends.org/) with Apache, MySQL, PHP 8+, phpMyAdmin
- A modern web browser

---

## 2. Installation Steps (fresh install)

1. **Install / start XAMPP.**
2. In the XAMPP Control Panel, **start Apache** and **start MySQL**.
3. Copy the entire `UBMSConnect` folder into your XAMPP `htdocs` directory, so
   the path looks like:
   ```
   C:\xampp\htdocs\UBMSConnect\
   ```
4. Open **phpMyAdmin** at `http://localhost/phpmyadmin/`.
5. Go to the **Import** tab, choose `database/ubmsconnect.sql`, and click **Go**.
   This creates the `ubmsconnect` database, all tables (including the new
   `audit_logs` table and the widened `users` table), and sample data.
6. Open `config/database.php` and confirm the credentials match your MySQL
   setup. The XAMPP defaults (`root` user, empty password) are already set.
7. Open your browser and go to:
   ```
   http://localhost/UBMSConnect/
   ```
   You will be redirected to the login page.
8. Log in using one of the demo accounts below.

### If you already have UBMSConnect installed (upgrading)

Do **not** re-import `ubmsconnect.sql` (it would fail on tables that already
exist). Instead:

1. Open phpMyAdmin → your `ubmsconnect` database → **Import**.
2. Import `database/migration_superadmin.sql` instead. This safely `ALTER`s
   your existing `users` table (adds `email`, `status`, `updated_at`,
   `last_login`, widens `role`) and creates the new `audit_logs` table,
   without touching your existing activities, venues, utilities, or
   maintenance data.
3. It also seeds one demo Super Admin (`superadmin` / `SuperAdmin123`) and
   one demo pending Staff registration, so you can test immediately.

---

## 3. Demo Accounts

| Role        | Username     | Password        |
|-------------|--------------|------------------|
| Super Admin | `superadmin` | `SuperAdmin123`  |
| UBMS Staff  | `staff`      | `staff123`       |
| UBMS Staff  | `staff2`     | `staff123`       |
| Event Host  | `host`       | `host123`        |
| Event Host  | `host2`      | `host123`        |
| Pending Staff (cannot log in yet) | `pstaff` | `staff123` |

Passwords are stored hashed (bcrypt via PHP's `password_hash()`), never in
plain text.

**Important:** `superadmin` / `SuperAdmin123` is a demo account meant for
local testing only. Before using this system anywhere beyond a local
capstone demo, either change its password (Super Admin → Profile → Change
My Password) or delete it and create your own account using the secure CLI
method described in Section 12.

---

## 4. Demonstration Walkthrough

1. Open `http://localhost/UBMSConnect/`.
2. Log in as **staff / staff123**.
3. View dashboard statistics (Total, Pending, Approved, Maintenance,
   Completed, Rejected).
4. Open the **Calendar**, browse months, and click any activity.
5. Change its status, e.g. **Pending → Approved**.
6. Confirm the change appears in **Activity Logs** with who changed it and
   when.
7. Go to **Maintenance**, add a note (e.g. "Projector in Conference Room is
   currently unavailable.").
8. Go to **Venues** or **Utilities** and update a status.
9. **Logout**.
10. Log in as **host / host123**.
11. Confirm the dashboard/calendar reflect the updated status.
12. Confirm there are no edit controls anywhere for the Host role.
13. Try to directly visit a staff-only URL while logged in as Host, e.g.
    `http://localhost/UBMSConnect/staff/maintenance.php` — the server
    returns a **403 Access Denied** page rather than just hiding a button.

---

## 5. User Roles

### Super Admin
**Administrative, not operational.** Account and user management, Staff
registration approval, the audit trail, school years and institutional
reference data. Super Admin deliberately does **not** duplicate UBMS Staff's
operational pages (activities, venues, utilities, maintenance) - Staff owns
those, and the four redundant Super Admin "* Overview" pages were removed in
Section 22.

### UBMS Staff
Full monitoring + status-management privileges: change activity status, add
maintenance notes, update venue/utility conditions, view the full audit
trail.

### Event Host
**Read-only.** Can view the dashboard, calendar, activities, venues, and
utilities, but every write action (`actions/*.php`) independently verifies
`$_SESSION['role'] === 'staff'` on the server and rejects the request with
HTTP 403 if it is not — the restriction is not just a hidden button.

---

## 6. Activity Statuses

`Pending` → `Approved` / `Rejected` → `Maintenance` → `Completed`

The system does not force a rigid workflow; Staff can move an activity to
any of the five statuses as real-world conditions require, and every change
is written to `activity_status_logs` for accountability.

---

## 7. "Real-Time" Behavior

Full WebSocket infrastructure was intentionally avoided to keep the project
understandable and reliable under XAMPP. Instead:

- `assets/js/app.js` exposes `startPolling(callback, intervalMs)`.
- Dashboard pages call `api/dashboard.php` every 15 seconds to refresh stat
  cards, today's activities, recent status changes, and venue/utility
  status — without a full page reload.
- The Activity Detail modal reloads its data immediately after a status
  change (`api/activities.php?action=detail`).
- `api/realtime.php` provides a lightweight "last changed" timestamp that
  could be used to gate a heavier refresh (kept simple for capstone scope).

---

## 8. Database Schema

See `database/ubmsconnect.sql` for the full schema. Summary:

| Table                  | Purpose                                             |
|-------------------------|------------------------------------------------------|
| `users`                 | Staff and Host accounts (hashed passwords, roles)   |
| `venues`                | Physical venues/facilities and their condition       |
| `activities`            | Pre-existing university activities being monitored   |
| `utilities`             | Building utilities (electricity, water, AV, etc.)     |
| `maintenance_logs`      | Maintenance notes tied to venue/activity/utility      |
| `activity_status_logs`  | Audit trail of every activity status change           |

Foreign keys enforce relational integrity; indexes exist on
`activity_date`, `status`, `venue_id`, and `host_id` for fast filtering.

---

## 9. File Structure

```
UBMSConnect/
├── index.php                  Role-based redirect to the correct dashboard
├── login.php                  Login form + authentication handler
├── logout.php                 Session destroy + redirect
│
├── config/
│   └── database.php            PDO/MySQL connection
│
├── includes/
│   ├── auth.php                 requireLogin() / requireRole() guards
│   ├── header.php                Shared <head> + topbar
│   ├── sidebar.php               Role-aware navigation
│   ├── footer.php                Closes layout, loads app.js
│   └── functions.php             Formatting + badge-class helpers
│
├── staff/                      Full-access pages (requireRole('staff'))
│   ├── dashboard.php
│   ├── calendar.php
│   ├── activities.php
│   ├── activity_view.php
│   ├── venues.php
│   ├── utilities.php
│   ├── maintenance.php
│   ├── logs.php
│   └── profile.php
│
├── host/                       Read-only pages (requireRole('host'))
│   ├── dashboard.php
│   ├── calendar.php
│   ├── activities.php
│   ├── activity_view.php
│   ├── venues.php
│   ├── utilities.php
│   └── profile.php
│
├── actions/                    Write endpoints, staff-only, enforced server-side
│   ├── update_status.php
│   ├── add_maintenance.php
│   ├── update_venue.php
│   └── update_utility.php
│
├── api/                        Read-only JSON endpoints (any logged-in user)
│   ├── activities.php           list / detail
│   ├── dashboard.php            stat bundle for polling
│   └── realtime.php             lightweight heartbeat
│
├── assets/
│   ├── css/style.css
│   ├── js/app.js
│   └── images/
│
└── database/
    └── ubmsconnect.sql
```

---

## 10. Security Notes

- Passwords hashed with `password_hash()` / verified with `password_verify()`.
- All SQL uses **PDO prepared statements** — no string-concatenated queries.
- Every protected page starts with `requireRole('staff')` or
  `requireRole('host')`, checked against `$_SESSION['role']`, not a hidden
  form field.
- Every write action under `actions/` re-checks the role independently, so
  a Host cannot bypass the UI by POSTing directly to the endpoint.
- Output is escaped via the `e()` helper (`htmlspecialchars`) to prevent
  reflected XSS.
- Session ID is regenerated on login to mitigate session fixation.

---

## 11. Explicitly Out of Scope

Event creation/scheduling, venue booking, mobile app, offline mode, AI,
predictive analytics, automated notifications, external integrations, IoT/
RFID/QR, and payments were intentionally **not** implemented — the research
scope is real-time monitoring and status tracking only.

---

## 12. Super Admin / Registration / Audit Log Update

This section documents the Super Admin role added on top of the original
Staff/Host system. Nothing in Sections 1-11 above was removed; this is
additive.

### New file structure

```
UBMSConnect/
├── register.php                    NEW - public self-registration
│
├── superadmin/                     NEW - Super Admin-only pages
│   ├── dashboard.php
│   ├── users.php                    User Management (create/edit/activate/deactivate/delete)
│   ├── pending.php                  Pending Staff Registrations (approve/reject)
│   ├── audit_logs.php               Full audit trail, filterable by action
│   └── profile.php                  Own profile + self password change
│
├── actions/
│   ├── manage_user.php              NEW - Super Admin-only: create/edit/status/delete
│   ├── approve_registration.php     NEW - Super Admin-only
│   └── reject_registration.php      NEW - Super Admin-only
│
├── includes/
│   └── audit.php                    NEW - logAudit() helper used system-wide
│
└── database/
    ├── migration_superadmin.sql     NEW - safely upgrades an existing install
    └── create_superadmin.php        NEW - CLI-only Super Admin creation tool
```

### Modified files

- `database/ubmsconnect.sql` - `users` table gained `email`, `status`,
  `updated_at`, `last_login`; `role` enum widened to include `super_admin`;
  new `audit_logs` table; sample data rewritten to use username lookups
  instead of hardcoded IDs (so it's order-independent).
- `includes/auth.php` - `requireRole()` now accepts an array; added
  `requireSuperAdmin()`, `isSuperAdmin()`, `isHost()`, and a live
  account-status check (`enforceLiveAccountStatus()`) that logs a user out
  immediately if a Super Admin deactivates/deletes/role-changes them mid-session.
- `includes/functions.php` - added `roleLabel()`, `accountStatusBadgeClass()`,
  `roleBadgeClass()`, `validRoles()`, `publicRegistrationRoles()`,
  `validAccountStatuses()`.
- `includes/sidebar.php` - added a Super Admin navigation branch; Staff/Host
  navigation is untouched.
- `includes/header.php` - role tag now shows `roleLabel()` (e.g. "Super Admin")
  instead of `ucfirst($role)`.
- `index.php` - now routes three ways (super_admin / staff / host).
- `login.php` - redesigned UI (icon fields, show/hide password, "remember this
  device"); added account-status messaging (pending/inactive/rejected) and
  `last_login` tracking. Underlying credential check is unchanged
  (`password_verify()` against the DB). **Later simplified** - see Section 18:
  the "Access Role" dropdown was removed and a single "Email or username"
  field is accepted.
- `actions/update_status.php`, `add_maintenance.php`, `update_venue.php`,
  `update_utility.php` - unchanged in behavior/permissions, but now also
  write to the new `audit_logs` table alongside their existing logging.
- `assets/css/style.css` - login page styles rewritten to match the
  requested reference design; all existing dashboard/table/badge/modal
  styles are untouched.

### Registration workflow

1. `register.php` is a **2-step form** (Section 18). Step 1 asks two
   **independent** questions:
   - **"I am a..."** - only **Event Host** or **UBMS Staff** (the system
     role; "Super Admin" is never rendered). The server whitelists the posted
     `role` against `['host', 'staff']`, so a tampered POST falls back to
     `host`, and `users.role` is stored exactly as chosen.
   - **"Are you a student?"** - a compact **ON/OFF toggle** (ON = student, the
     default), kept deliberately small so Step 1 stays short. It is a
     registration detail, not a third role. The server
     whitelists it against `['student', 'non_student']`; the answer only
     decides which Step-2 fields appear and are validated (students:
     Department *required* + Course *required* + Organization *optional*;
     non-students: Department *optional* + UBMS office/unit *required*).
2. Accounts are created with:
   - **Event Host** (role `host`) → `status = 'active'` immediately
     (no Staff approval is needed, and their password is the one they just
     chose).
   - **UBMS Staff** (role `staff`) → `status = 'pending'` and cannot log in
     (`login.php` explicitly checks status and blocks pending/rejected/
     inactive accounts with a clear message).

   The role/approval behaviour never depends on the student answer - a
   student UBMS Staff is still `pending`, a non-student Event Host is still
   `active`.

**Storage note:** role and student status are stored separately.
`users.role` holds `host`/`staff` exactly as chosen, while the student answer
lives in the legacy `users.affiliation` column (`'student'` = student,
`'ubms_staff'` = non-student - the old enum value is kept so **no schema change
or migration** is needed, and the column no longer encodes the role). Everything
the user sees is role-driven (the registration cards, the profile "Role" row)
plus a "Student Status" row/column (profiles, pending-registrations table) for
the student answer.
3. The username is chosen by the user in Step 1 (3-30 characters: letters,
   numbers, underscores and hyphens). It is validated server-side, checked for
   availability before the account is created (plus the `UNIQUE` index as the
   backstop), and stored exactly as entered - it is never auto-generated or
   silently changed. Users can still sign in with their school email instead.
4. A Super Admin reviews pending Staff accounts at `superadmin/pending.php`
   and clicks Approve (→ active) or Reject (→ rejected). Both actions are
   written to `audit_logs`.

### How to create/log in as the first Super Admin

Two supported methods:

1. **Demo seed account** - `superadmin` / `SuperAdmin123` (Section 3). Local
   testing only; change the password or delete the account before any real use.
2. **Secure CLI script** - `database/create_superadmin.php` (listed under
   *New file structure* above). Run it from a terminal:
   `php database/create_superadmin.php`. It asks for the account details and
   writes a bcrypt hash straight into `users`, so no password is ever typed
   into a browser form. See Section 13 for its one Windows limitation
   (CLI password prompts are not masked).

### How to test each role

1. **Super Admin** (`superadmin` / `SuperAdmin123`): log in, confirm the
   sidebar shows User Management / Pending Registrations / Audit Logs /
   Schedule Conflicts / Reports / School Years / Institutional Records, and
   that there are **no** Activity / Venue / Utility / Maintenance Overview
   entries (see Section 22); try
   visiting `staff/maintenance.php` directly - you'll get a 403.
2. **UBMS Staff** (`staff` / `staff123`): confirm existing dashboard/calendar/
   status-change/maintenance functionality is unchanged; try visiting
   `superadmin/users.php` directly - you'll get a 403.
3. **Event Host** (`host` / `host123`): confirm read-only access is
   unchanged; try visiting `staff/dashboard.php` or `superadmin/dashboard.php`
   directly - both return 403.
4. **Registration**: go to `register.php` and register
   (a) an **Event Host** account - confirm you can log in immediately using the
   school email you entered, then (b) a **UBMS Staff** account - confirm you
   *cannot* log in (pending message shown), then log in as Super Admin and
   approve it at `superadmin/pending.php`, then confirm that account can now
   log in as Staff. Repeat a registration with **"No, I am not a student"**
   (and one Staff account with **"Yes"**): the Step-2 field set must change,
   but the role and approval behaviour must not.
5. **Self-lockout protection**: as `superadmin`, open User Management and
   try to deactivate or delete the `superadmin` account itself while it's
   the only active Super Admin - the action is rejected server-side with an
   explanatory message.

---

## 13. Known Limitations of the Super Admin Update

Being transparent about what is **not** implemented, per explicit
instruction not to claim something works unless it does:

- **No CSRF tokens.** The original project didn't have them; none were
  added here either, for consistency with the existing codebase. All
  state-changing requests rely on session authentication + server-side role
  checks only.
- **No real "forgot password" flow.** The link on the login page opens a
  `mailto:` to a placeholder support address. Password resets are performed
  manually by a Super Admin via User Management (this part does work).
- **Windows CLI password prompts are visible**, not masked, when running
  `create_superadmin.php` on Windows (PHP CLI has no built-in way to hide
  input on Windows without an external extension). It is masked on
  macOS/Linux via `stty`.
- **No pagination.** Audit Logs caps at the most recent 300 rows; User
  Management shows all users on one page. Fine at capstone data volumes,
  would need pagination at real-world scale.
- **Deleting a user who owns activities is blocked** rather than cascaded,
  to protect existing monitoring/audit history - the Super Admin must
  deactivate such an account instead.
- **"Remember this device for 30 days"** on login extends the PHP session
  cookie lifetime; it does not implement a separate persistent "remember me"
  token system, so clearing browser cookies still ends the session early.

---

## 17. Feature Expansion Update (Dynamic Venues/Utilities, Notifications, Settings, Password Reset)

This section documents the third round of changes on top of everything in
Sections 1-16. Nothing existing was removed; this is additive.

### New migration required

If you already have UBMSConnect installed (including the Super Admin
update), import **one more file** via phpMyAdmin's Import tab:

```
database/migration_features.sql
```

This adds `location`/`description`/`is_active`/`created_by` to `venues`,
`category`/`is_active`/`created_by`/`created_at` to `utilities`, widens the
venue status enum to include `Unavailable`, and creates four new tables:
`notifications`, `notification_preferences`, `dashboard_preferences`,
`password_reset_tokens`. No existing data is dropped. Fresh installs get
all of this automatically via the updated `database/ubmsconnect.sql`.

### New files

```
notifications.php                      Shared notification inbox (any role)
settings.php                           Shared settings (Appearance/Account/Dashboard/Notifications)
forgot_password.php                    Password recovery request (Super Admin assisted)
includes/password_recovery.php         Recovery request helpers, rate limits, CSRF for review actions
includes/notifications.php             notifyUser() / notifyRole() helpers
api/notifications.php                  list / unread_count / mark_read / mark_all_read
actions/manage_venue.php               Staff: create/edit/deactivate venues
actions/manage_utility.php             Staff: create/edit/deactivate utilities
actions/update_profile.php             Self-service name/email/password change (any role)
actions/update_preferences.php         Self-service dashboard + notification preferences
database/migration_features.sql        Migration for existing installs
```

### Modified files

`database/ubmsconnect.sql`, `includes/{functions,header,sidebar}.php`,
`assets/{css/style.css,js/app.js}`, `login.php`, `register.php`,
`actions/{update_status,update_venue,update_utility,add_maintenance,
approve_registration,reject_registration,manage_user}.php`,
`staff/{venues,utilities,dashboard,calendar,maintenance}.php`,
`host/{venues,utilities,dashboard,calendar}.php`.

### Dynamic Venues & Utilities

UBMS Staff now see a **+ Add Venue** / **+ Add
Utility** button. Adding, editing, and deactivating are real database
operations via `actions/manage_venue.php` / `manage_utility.php` -
deactivation is a soft toggle (`is_active`), not a delete, so existing
activities/maintenance records that reference a venue/utility are never
orphaned. Event Hosts only ever see active venues/utilities and have zero
write controls (confirmed: no admin buttons render on `host/venues.php` or
`host/utilities.php`, and the backend independently rejects non-staff
writes regardless of what the UI shows).

Venue statuses are **Available / In Use / Under Maintenance / Unavailable**
- this intentionally stays separate from the Activity status vocabulary
(Pending/Approved/Rejected/Maintenance/Completed), per the existing,
working system design; forcing the activity statuses onto venues would
have broken the dashboard's venue widget and existing badge logic.

### Notifications

A bell icon in the top bar (visible on every authenticated page) polls
`api/notifications.php?action=unread_count` every 20 seconds and shows a
dropdown of the 8 most recent notifications on click. The full history is
at `/UBMSConnect/notifications.php`. Every notification is scoped to
`currentUserId()` in the SQL itself - there is no way to see another
user's notifications.

Notifications are generated for: new Staff registration (-> all Super
Admins; Event Host / host registrations do not notify anyone because they are
active immediately), Staff approval/rejection (-> that user), activity status
change (-> the activity's host), account activation/deactivation (-> that
user),
new maintenance record (-> Staff + Super Admin), and venue/utility status
changes or creation (-> Staff + Super Admin). Super Admin's copies of the
venue / utility / maintenance notifications link to **Audit Logs** (filtered
to the matching action) instead of to pages of their own - see Section 22.
Each respects the acting
user's own `notification_preferences` - if a user has turned a category
off, that notification is never created for them (not just hidden).

### Settings

`/UBMSConnect/settings.php` is available to all three roles:
- **Appearance**: Light/Dark/System, applied via CSS variable overrides
  under `html[data-theme="dark"]` (no duplicated markup), stored in
  `localStorage`, applied before paint via an inline script in
  `includes/header.php` to avoid a flash of the wrong theme.
- **Account**: name/email edit + password change (requires current
  password verification). Role and username are explicitly **not**
  editable here.
- **Dashboard Preferences**: toggling a section off actually removes it
  from the dashboard (server-rendered) and the AJAX polling script was
  updated to null-check every element it touches, so hiding a section
  never breaks the refresh of the sections that remain visible.
- **Notification Preferences**: four category toggles that are read by
  `notifyUser()` before every notification is created.

### Password Reset

A genuine token-based flow: `bin2hex(random_bytes(32))` token, only the
SHA-256 **hash** is stored, 1-hour expiration, single-use (marked
`used_at` on consumption, and any prior unused token for that user is
invalidated when a new one is requested). Since this XAMPP environment has
no mail server, the reset link is surfaced directly on the confirmation
page instead of emailed, **clearly labeled** as a local-development
stand-in - this is called out explicitly in the UI, not hidden.

### Known simplifications in this round (being explicit, not claiming more than is true)

- **Week calendar view is not implemented** - only Month and List, as
  disclosed directly in the Settings page UI next to that dropdown.
- **Rejected/pending accounts cannot see their own notification** about
  the rejection, since they can never successfully log in to view it -
  the notification row is still created for record-keeping, but is only
  practically visible if the account is later reactivated.
- **Pre-login pages** (login, register, forgot/reset password) always
  render in light mode - the dark mode theme script only runs on pages
  that include `includes/header.php` (i.e. after authentication).
- **No CSRF tokens**, consistent with the rest of the existing codebase.
- Everything above was verified via static code tracing and a full
  brace/paren balance check across all 57 PHP files in this sandbox, not
  by executing PHP/MySQL - please re-test in your actual XAMPP
  environment before considering this final.

---

## 18. Registration & Login Simplification Update

Implements the moderator's recommendation: *"Simplify the Login and
Registration Process - consider pre-registering the recognized organizations
along with their respective departments and courses; ensure the information
is accurate and regularly updated."*

Nothing was removed from the underlying data model or security checks - this
is a **UX simplification** on top of the same database columns.

### What changed

| Before | Now |
|--------|-----|
| Login asked for Role + Username + Password | Login asks for **Email or username** + Password. The role was *always* read from the database anyway, so the dropdown only created a "wrong role selected" failure. |
| Registration had 11 inputs on one long page | **2 short steps, 9 inputs.** Step 1 = who you are (system role, student status, full name, school email). Step 2 = details for that student status + password. |
| User invented a username | **Username is chosen by the user** in Step 1 (validated 3-30 chars, server-side uniqueness), and users can also sign in with their email. |
| "Confirm password" box | Removed; replaced with a show/hide password toggle (the login page already used this pattern). |
| "University ID" field | **Removed from registration** (see "Later adjustments" below). The school already knows its own IDs, and it was the second field the user had to type. |
| Department was required for everyone | Department is required for **students**, **optional for non-students** (who provide an Office/Unit instead) - regardless of role. |
| A `required` course/office field could not be reached for the other student status | The irrelevant group is hidden and its `required` flag is toggled by JS; the server re-validates the applicable branch only. A hidden-group value posted anyway is ignored, never required. |
| All registrations were inserted as `pending` (contradicting README §12 and the login notice) | **Event Host (role `host`) = `active`** (can sign in immediately), **UBMS Staff (role `staff`) = `pending`** (Super Admin approval) - derived from the role card alone, never from student status. This matches the documented workflow. |
| Every registration notified all Super Admins | Only Staff registrations notify Super Admins, since Event Hosts need no decision. |

### Reference data (already in place, now used consistently)

`departments`, `courses` and `organizations` are **lookup tables** managed by
a Super Admin at `superadmin/reference_data.php` (via
`actions/manage_reference.php`). Both login and registration only ever *pick*
from those official lists - nothing academic is free-typed - and inactive
rows are excluded everywhere, so the lists stay accurate by construction.
Keep them updated by adding/renaming entries there rather than in the form.

### Files touched in this round

- `register.php` - rewritten as the 2-step wizard (readable, commented PHP
  instead of the previous minified single-line file). All server-side
  validation from the old version is retained and extended (length limits,
  per-branch rules, duplicate email/university ID check).
- `login.php` - role dropdown removed, single `identifier` field,
  `WHERE username = ? OR email = ?` lookup (exact username match wins),
  updated notice text, generic "Invalid email/username or password" message.
- `assets/css/style.css` - new "Registration page (simplified 2-step form)"
  block (`.register-progress`, `.step-dot`, `.affiliation-choice`,
  `.step-actions`, `.has-error`, `.login-form [hidden]`). Also added
  `input[type=email]` to the shared input rule (and its dark-mode override) -
  the school email field previously received no form styling at all.
- `register.php`, `login.php`, `includes/header.php` - the stylesheet link now
  carries `?v=<filemtime>` so a changed `style.css` is actually fetched
  instead of being served from the browser cache (a stale cached stylesheet is
  what made the new step indicators look unstyled).
- `superadmin/pending.php`, `includes/affiliation_profile.php` - made the
  optional University ID display tolerant of accounts that don't have one.
- `README.md` - this section, plus §12's registration workflow, login bullet,
  test steps and notifications list updated to match the code.

### Fix after the first review of this section

The first version of the wizard opened on **Step 2** on a fresh visit, because
`$startStep` defaulted to `2` whenever `$errors` was empty:

```php
$stepOneErrors = array_intersect_key($errors, ...);
$startStep = $stepOneErrors ? 1 : 2;   // on a clean GET $errors is empty -> 2
```

It now defaults to Step 1 and only moves to Step 2 when a submit failed on a
Step-2 field, so a first-time visitor always sees "About you" first.

### Later adjustments

- **The "University ID" field was removed from registration.** Step 1 is now
  just *role + student status + full name + school email* (the whole form is
  down to **7 typed values**). Related changes:
  - Duplicate detection is now **email-only** (`SELECT id FROM users WHERE email = ?`);
    `users.university_id` is inserted as `NULL` for new accounts. The column
    itself is *not* dropped, so existing rows keep their values and a Super
    Admin can still set one manually in the database.
  - `superadmin/pending.php` used to print `university_id · email`, which
    would have rendered a stray leading "·" for new accounts; it now builds
    the line with `implode(' · ', array_filter([...]))`.
  - `includes/affiliation_profile.php` only renders the "University ID" row
    when the account actually has a value (used by all three profile pages),
    so new accounts no longer show a "Not set" row.

- **Role and student status are now separate questions.** The Step-1 "I am a..."
  cards only choose the system role (`users.role` = `host`/`staff`; host →
  `active`, staff → `pending`), and a compact **"Are you a student?" toggle**
  (ON = student, the default; a registration detail, not a
  third role) decides the Step-2 field set: students get Department
  (required) + Course (required) + Organization (optional); non-students get
  Department (optional) + UBMS office/unit (required). The role's approval
  note stays visible for every combination, JS toggles the two dimensions
  independently (`data-when` = student status, `data-role-when` = role), and
  the server validates only what was displayed. The student answer is stored
  in the legacy `affiliation` column (`'student'` / `'ubms_staff'` =
  non-student), so **no schema change was needed** and existing rows keep
  their meaning; profile pages and the pending table now show a **Student
  Status** row/column while every role label comes from `role`. Verified live
  with all four combinations (below).

### How this was verified (actually executed, not just reasoned about)

With XAMPP Apache + MySQL running locally:

1. `php -l register.php` and `php -l login.php` → no syntax errors.
2. `GET /UBMSConnect/register.php` and `login.php` → HTTP 200; the register
   page no longer contains a `username` or `confirm_password` input, and the
   login page no longer contains `role_option`.
3. `POST login.php` with `identifier=john.doe@ubmsconnect.local` (a demo
   account's **email**) → redirected into the Staff dashboard; the same with
   `identifier=superadmin` (a **username**) → Super Admin dashboard. Both
   paths work.
4. `POST login.php` with a wrong password → generic error, no role wording.
5. `POST register.php` as an **Event Host** (`affiliation=student`) → login page
   shows "Account created"; database row =
   `cline.test.student / host / active / department 1 / course 1` (the stored
   `affiliation` value is still `student`).
6. `POST register.php` as **UBMS Staff** (no department, with an office unit)
   → "Registration received" notice; database row =
   `cline.test.staff / staff / pending / office_unit set`.
7. `POST register.php` with empty/invalid values → errors shown, form
   re-opened at Step 1, and no row written (verified in MySQL).
8. `POST register.php` with an already-registered email → rejected with
   "already registered".
9. The four test rows (and their audit-log/notification rows) were deleted
   afterwards, leaving the demo data untouched.
10. Re-checked after the Step-1/Step-2 regression fix: a fresh
    `GET register.php` renders `data-start-step="1"`, a submit that fails only
    on Step-2 fields re-opens at Step 2, a submit that fails on Step-1 fields
    re-opens at Step 1, the served `style.css` contains the new `.step-dot`
    rules, and the happy path still creates `cline.step3 / host / active`
    (deleted again afterwards).

### How the role / student-status split was verified (actually executed)

With XAMPP Apache + MySQL running locally:

1. `php -l register.php`, `includes/affiliation_profile.php`,
   `superadmin/pending.php` → no syntax errors.
2. `GET /UBMSConnect/register.php` → HTTP 200; the page contains
   `name="role"`, `name="student_status"` and the "Are you a student?"
   question, and no longer contains `name="affiliation"`.
3. **All four combinations POSTed** (with the reCAPTCHA test key) → stored
   exactly as required:

   | Posted | role | status | affiliation | Step-2 data | login notice |
   |---|---|---|---|---|---|
   | Event Host + Student | `host` | `active` | `student` | dept 1, course 1, org 1 | "Account created" |
   | Event Host + Non-student | `host` | `active` | `ubms_staff` | dept 2 + office; bogus `course_id=999999` ignored (NULL) | "Account created" |
   | UBMS Staff + Student | `staff` | `pending` | `student` | dept 1, course 1, org 2; office never asked (NULL) | "Registration received" |
   | UBMS Staff + Non-student | `staff` | `pending` | `ubms_staff` | office set, no course posted | "Registration received" |

4. **Login**: both host accounts (student *and* non-student) sign in straight
   to `host/dashboard.php` (`last_login` set); both staff accounts are
   blocked with *"Your UBMS Staff account is pending Super Admin approval."*
5. **Validation matches the displayed set**: student without a course →
   *"Please choose a course offered by your department."*; non-student
   without an office → *"Please enter your UBMS office or unit."*; both
   rejected with no row written.
6. **reCAPTCHA still enforced**: a registration POST without
   `g-recaptcha-response` is rejected (*"Please tick the I'm not a robot box
   to continue."*) and no account is created; with the test key it passes.
7. **Tampering**: `role=super_admin` falls back to `host`; a bogus
   `student_status` falls back to `student`; values posted from a hidden
   field group are ignored rather than required.
8. **Display**: `host/profile.php` shows the "Student Status" row (= Student)
   with the role still shown as Event Host; `superadmin/pending.php` shows
   the Student Status column with Student / Non-student values.
9. `verify_email.php` and `resend_verification.php` → **HTTP 404** (email
   verification stays removed).
10. All test rows (users + their audit-log/notification rows) were deleted
    afterwards, leaving the demo data untouched.

---

## 19. School Year, Report Generation & Report Filtering (Items 8, 7, 9)

Implements the moderator's recommendations:

- **8. "Include School Year Information"**
- **7. "Enable Automated Report Generation"** - weekly and monthly reports for
  monitoring / administrative decision-making
- **9. "Add Report Filtering Options"** - filter generated reports by
  organization name, month, and school year

These three were built together because the filters (9) only mean something
on top of the report they filter (7), and both need the school-year data (8).

### Migration required

On an existing install, import **one more file**:

```
database/migration_reports.sql
```

It only **adds** things and backfills from data already present - nothing is
dropped, renamed, or overwritten, and every step is guarded so the file can be
re-run safely (verified by running it twice). Fresh installs get all of it from
`database/ubmsconnect.sql`.

What it does:

1. Creates `school_years` (`name`, `start_date`, `end_date`, `is_active`) and
   seeds two editable defaults (`AY 2025-2026`, `AY 2026-2027`).
2. Adds two **nullable** columns to `activities`:
   `school_year_id` (FK -> `school_years`, `ON DELETE SET NULL`) and
   `organization_id` (FK -> `organizations`, `ON DELETE SET NULL`).
3. Seeds six sample organizations and assigns the two demo hosts to two of
   them, so the Organization filter has data on a fresh install (the
   `organizations` table is otherwise empty).
4. Backfills `school_year_id` from each activity's date and
   `organization_id` from the host's account.
5. Prints a short summary of what it filled in.

### Item 8 - School Years (Super Admin -> School Years)

A new page where the Super Admin defines the academic calendar; nothing about
the range is hard-coded, exactly as requested ("make it so that I can define it").

- **Add / edit / activate / delete** school years. Only one can be active, and
  the active one is the default selection on the Reports page.
- Ranges **may not overlap** - otherwise "which year does this activity belong
  to" is ambiguous - and the server rejects an overlap with a message naming
  the clashing year. Start must precede end. The **last remaining** school year
  cannot be deleted.
- **Re-sync activities** button: `school_year_id` is *fully recomputed* from
  each activity's date (so editing a range fixes every record, and a date
  falling outside every range becomes `NULL`), while `organization_id` is only
  filled in where it is still empty. The page shows how many activities
  currently fall outside every defined range.

`includes/school_year.php` holds the helpers (`getSchoolYears()`,
`getActiveSchoolYear()`, `schoolYearForDate()`, `resyncActivitySnapshots()`,
`monthOptions()`, …) and degrades gracefully if the table has not been
migrated yet.

### Item 7 - reports.php (UBMS Staff + Super Admin only)

Event Hosts are blocked with a 403 on both the page and the action endpoints.
Reports are read-only and never modify monitoring data.

The report contains:

| Section | What it shows |
|---|---|
| Summary strip | Activities, total time logged, venues used, organizations represented, event hosts |
| Breakdown by period | One row per **week / month / school year**, with per-status columns and a grand-total footer |
| By organization | Activities, time, venues, hosts, and per-status counts per organization |
| Venue utilization | Activities, hours used, days used, organizations served, last used date |
| Status breakdown | Per-status count, share percentage, and time |
| Event hosts in period | Top 10 hosts with their organization and activity/time totals |
| Maintenance notes | Notes logged between the period's first and last activity date |

**Save as PDF** uses `window.print()` plus a dedicated `@media print` block, so
no PDF library is needed on a plain XAMPP setup - the button opens the browser's
print dialog where "Save as PDF" is a destination. In print mode the sidebar,
topbar, filters and action buttons are hidden, table headers repeat on every
page, and rows/sections avoid being split across sheets. **Export CSV** streams
a UTF-8 (BOM-prefixed) file with every section, for Excel/Sheets.

### Item 9 - Report filters

All filters are in one GET form, so any generated report can be bookmarked,
shared, re-opened from browser history, printed, or exported:

| Filter | Notes |
|---|---|
| **School year** | Defaults to the active school year; "All school years" is available |
| **Month** | With a specific school year selected this pins that month *inside* it; with "All school years" it means that month in every year |
| **Organization** | Reports on the snapshot recorded on the event (see below) |
| **Venue** | Optional extra filter |
| **Status** | Optional extra filter |
| **Group by** | Week / Month / School year - chooses the period breakdown |

If a month falls outside the selected school year the page does not just show
an empty table; it says so explicitly ("June may fall outside AY 2026-2027
(August 1, 2026 - July 31, 2027)"), so it cannot be mistaken for a bug.

### Data model note: why the organization is a snapshot

`activities.organization_id` is filled from the host's account **at
monitoring time** and is deliberately *not* recomputed on every re-sync. A
report is a historical document: it should keep saying "Computer Science
Society" because that was the organization then, even if the student later
moves to another one. The Organization is assigned per account in
**Super Admin -> User Management** (new "Organization" column + picker in the
create/edit modal, validated against the organizations table and written to
the audit log as `Updated User Organization`), and the list itself is managed
in **Super Admin -> Institutional Records**.

### Files added / changed in this round

**New:** `reports.php`, `includes/school_year.php`,
`actions/manage_school_year.php`, `superadmin/school_years.php`,
`database/migration_reports.sql`.

**Modified:** `database/ubmsconnect.sql` (school_years table, the two
`activities` columns + FKs, seeds, and the same backfill at the end),
`includes/sidebar.php` (Reports for Staff + Super Admin; School Years for
Super Admin), `actions/manage_user.php` and `superadmin/users.php`
(organization assignment), `staff/activity_view.php` +
`host/activity_view.php` (School Year / Organization rows),
`assets/css/style.css` (report styles + the `@media print` block).

### How to test this round

1. **Super Admin -> School Years**: confirm **AY 2026-2027** is active and that
   the table shows 15 activities against it. Try adding a year whose dates
   overlap an existing one - it is rejected with a message naming the clash.
2. Click **Re-sync activities** - it reports how many records were updated and
   warns if any activity still falls outside every range.
3. **Reports** (as Staff or Super Admin): the page opens on the active school
   year grouped by month. Switch **group by** to Week and then School year.
4. Filter by **Organization** (e.g. Computer Science Society) and confirm the
   summary, period, organization and venue tables all narrow to that
   organization.
5. Pick a month that is outside the selected school year (e.g. June inside
   AY 2026-2027) - the page explains why there is no data.
6. Click **Save as PDF / Print** and choose "Save as PDF" as the printer; the
   sidebar/topbar/filters are gone from the output and the report fills the
   sheet. Then click **Export CSV** and open it in Excel.
7. Log in as **Event Host** and open `/UBMSConnect/reports.php` directly -
   403 Access Denied, and the sidebar has no Reports link.

### Verified (executed against this XAMPP install)

- `php -l` clean on all 9 new/changed files.
- `migration_reports.sql` was run **twice** - the guards make the second run a
  no-op. Result: 2 school years, 6 organizations, and all 15 activities tagged
  with both a school year and an organization.
- `reports.php` as Staff: 200, all six filters render, all six sections render,
  group-by week/month/school-year all return correct rows, the organization
  filter narrows the report, and the outside-the-school-year month shows the
  explanatory empty state.
- CSV export: `text/csv`, attachment filename, 56 lines, and all four section
  headers present.
- Access control: Event Host gets **403** on `reports.php` *and* on
  `actions/manage_school_year.php`; the Host sidebar has no Reports link.
- School-year validation, all exercised live: overlapping range rejected
  ("overlaps AY 2025-2026"), start-after-end rejected, blank name rejected,
  unknown id rejected, unknown action rejected, add/activate/delete all
  succeed (the temporary test year was deleted again afterwards, and the
  `COUNT(*)` guard that protects the last remaining school year is in place).
- User Management organization plumbing, end-to-end: create with an
  organization stores it; create/edit with a **non-existent** organization is
  now **rejected** instead of being silently dropped; moving a user from
  organization #6 to #5 stores it and writes an `Updated User Organization`
  audit row ("Organization #6 -> Organization #5"); clearing it sets `NULL`
  and audits "Organization #5 -> Not set". Test accounts were deleted
  afterwards - the database is back to its 9 real accounts, with the demo
  hosts still on their assigned organizations.

### Known limitations (explicit, not claimed otherwise)

- **No PDF library.** "Save as PDF" is the browser's print-to-PDF. A generated
  PDF file (rather than a print view) would need a library such as Dompdf,
  which this plain-XAMPP project has no Composer setup for.
- **The Organization filter can only be as good as the data.** Activities whose
  host has no organization assigned are grouped under "Not assigned", and
  since accounts are only assigned an organization by a Super Admin, an
  unassigned host will show up there until someone assigns one.
- **Reports read stored monitoring records only** - they do not create, edit,
  or delete anything, and there is no scheduled/emailed delivery.
- Like the rest of this codebase, these new endpoints use session
  authentication + server-side role checks and **no CSRF tokens**.

---

## 20. Dashboard Layout Refresh (Visual Tidiness)

All three dashboards (`staff/`, `host/`, `superadmin/`) were re-laid-out so the
information reads in clear blocks instead of a scatter of boxes. **No query,
preference flag, element ID or AJAX contract changed** - this is presentation
only.

### What was untidy before

- 6 (staff/host) and 8 + 5 (super admin) separate stat boxes floated at the top,
  each with its own border and shadow, and the Super Admin page had to override
  the grid inline (`style="grid-template-columns: repeat(4, 1fr)"`) to squeeze
  them in.
- Cards sat directly in a 2-column `.grid-2`, so rows ended at different
  heights, and on the Host page the Utility card was full-width while every
  other card was half-width.
- No grouping or rhythm - every card had exactly the same visual weight.

### What it looks like now

- **Compact, labelled KPI strips.** Each group sits under a small uppercase
  section label ("Accounts" / "Activities" on Super Admin, "Activity overview"
  on Staff and Host). The strips use `.stat-strip.compact`: smaller icon tile
  and number, tighter padding and gap, and no drop shadow or hover lift, so
  the many statistic cards no longer dominate the page.
- **A clear hierarchy on Staff and Host**: heading + last-refreshed info,
  then the compact activity overview, then **Today's Activities as a
  full-width `.dash-card-main` focal card** (body capped at 400px), with the
  supporting panels arranged in a row below it.
- **An adaptive supporting row** (`.dash-grid` > `.dash-col`): Staff puts
  Recent Status Changes beside the stacked Venue/Utility cards; Host puts
  Venue and Utility side by side (`.dash-grid.cols-2`). When a dashboard
  preference hides one group, the remaining column spans the full width
  (`.dash-grid.cols-1`) instead of leaving an empty gap.
- **Prioritised pending registrations on Super Admin**: "Pending
  registrations" now sits directly after Total users with a conditional
  "Review needed" link, and the Pending Staff Registrations card carries an
  "N pending" badge - both point at `pending.php`. The existing Review all /
  View audit trail actions are unchanged.
- **Every card keeps the same header**: title + a "View all" link to the matching
  full page (`staff/logs.php`, `staff/venues.php`, `superadmin/audit_logs.php`, …).
- **Capped, scrollable bodies** (`.dash-card-scroll`: 315px for supporting
  cards, 400px for the main card) so a long venue/utility/audit list
  can't stretch the whole layout.
- Responsive: 6 -> 3 -> 2 KPI columns, and every `.dash-grid` variant stacks
  to one column at <= 1100px.
- Scoped styles: `.compact` / `.dash-card-main` / `.stat-hint` / `.cols-*`
  apply only to the three dashboards, so `conflicts.php` and `reports.php`
  keep their existing stat appearance. No JavaScript, query, route, or
  permission changes were made.

### Files changed

- `assets/css/style.css` - removed the old `.stat-grid`/`.stat-card` rules (plus
  their two now-dead responsive overrides at the end of the file) and added
  `.stat-strip` / `.stat-item` / `.stat-group-label` / `.dash-grid` / `.dash-col`
  / `.dash-card*`, with the matching `html[data-theme="dark"]` entries.
- `staff/dashboard.php`, `host/dashboard.php`, `superadmin/dashboard.php` -
  markup only.

### Verified

- `php -l` clean on all three, and each dashboard returns HTTP 200 for its role.
- Every live-updating element ID still exists (`statTotal`, `statPending`,
  `statApproved`, `statMaintenance`, `statCompleted`, `statRejected`,
  `lastRefreshTime`, `todayTable`, `recentTable`, `venueTable`, `utilityTable`),
  so the 15-second polling in each dashboard's inline script keeps working.
- Settings -> Dashboard Preferences still hides sections: with
  `show_stats=0, show_venues=0` on a temporary account, the KPI strip and the
  Venue card disappeared while the Utility card stayed and the grid stayed
  intact (temporary account deleted afterwards).
- No references to the removed `.stat-grid`/`.stat-card` classes remain anywhere
  in the PHP/CSS/JS, and `style.css` braces still balance (295/295).

---

## 21. Venue Schedule Conflict Detection (Item 11)

Two activities can no longer quietly claim the same venue at the same time.
Detection is **live** - there is no stored "has conflict" flag anywhere - and the
only write path that can create a double booking (a status change to *Approved*)
now stops, shows the clash, and records the answer if the person insists.

### Migration required

Run `database/migration_conflicts.sql` once (phpMyAdmin → Import, or
`mysql -u root < database/migration_conflicts.sql`).

It adds exactly one table, `activity_conflicts`, and two clearly-labelled sample
activity rows. Nothing is dropped or rewritten, and every statement is guarded,
so the file is safe to run twice and safe on a database that already has data.

### How a conflict is defined

A conflict is **two activity records that share the same `venue_id`, fall on the
same `activity_date`, and whose time ranges overlap**:

```
other.start_time < this.end_time   AND   other.end_time > this.start_time
```

- **Back-to-back is not a conflict.** A record ending 11:30 and another starting
  11:30 do not overlap, so they are never reported.
- Records that are **Rejected** or whose venue is **Under Maintenance** do not
  occupy the venue, so they are never reported as clashing.
- Completed records *do* occupy the venue, but are hidden by default so the page
  shows what still needs action rather than history.

Each reported clash carries a severity:

| Severity | When | Shown by default |
|-----------|-----------------------------------------------|------------------|
| Confirmed | both records are Approved | yes |
| Potential | at least one record is still Pending | yes |
| Historical| at least one record is Completed | no (`include_completed=1` to show) |

### What happens when staff approves a clash

`actions/update_status.php` is the only place a double booking can enter the
system, so the guard lives there:

1. **POST without `confirm_conflict`** → the request is refused and the whole
   transaction is rolled back. The JSON response is
   `{"success":false,"conflict":true,"venue_name":...,"conflicts":[...]}`, where
   each entry includes the other record's name, status, times, the exact overlap
   window and the overlap duration. **Nothing is written** - the status is
   unchanged and no status log row is created.
2. The UI turns that response into a confirmation dialog. Only after the person
   explicitly confirms does `assets/js/app.js` (`submitStatusChange(id, true)`)
   resend the same POST with `confirm_conflict=1`.
3. **POST with `confirm_conflict=1`** → the status changes, the override is
   written to `activity_conflicts`, and an audit entry **"Conflict Overridden"**
   is added next to the normal "Status Changed" entry.

Approving something that clashes notifies **everyone affected**: the activity's
own Event Host, the other Event Host(s) whose records are being overlapped, all
active UBMS Staff, and all Super Admins. The notification names the venue, both
activities, both time ranges and the exact double-booked window, and links to
`conflicts.php` for the staff/admin recipients. (The Staff member who performed
the action is not notified about their own action.)

### The override trail

`activity_conflicts` exists for one reason: to make "we prevented double booking"
mean *"never silent"* rather than merely *"blocked"*. Each row records the venue,
date, both activity ids, the overlap window, who allowed it (`detected_by`), when,
and the optional reason.

The table has `UNIQUE (primary_activity_id, conflicting_activity_id)`, so
overriding the **same pair** a second time does not create a duplicate row - it
increments `override_count` and refreshes `reason`/`updated_at`. That behaviour is
verified below.

The trail is surfaced on `conflicts.php` as the **"Approved anyway (audit trail)"**
table, which only appears once at least one override exists.

### Where the feature appears

| Surface | What it does |
|-----------------------------|----------------------------------------------|
| `conflicts.php` (Staff + Super Admin) | KPI strip, severity legend, filters (severity / venue / exact date / school year / month / organization), conflict table, "Venues by number of conflicts" hotspots, override audit table, CSV export, print button |
| `api/conflicts.php` | `action=check&id=N` and `action=count` (JSON) |
| `staff/activity_view.php` | Red "Schedule conflict" banner + confirm dialog when approving |
| `host/activity_view.php` | Same banner, read-only wording |
| `staff/venues.php`, `host/venues.php` | Schedule Availability panel gains a "Double-booked" badge and a note naming both records and the overlap window |
| `includes/sidebar.php` | "Schedule Conflicts" link, Staff and Super Admin only |

`api/conflicts.php?action=check` is available to any logged-in user because the
data it reads is already visible to them in the Schedule Availability panel, but
an **Event Host may only check their own records** - enforced in the endpoint, not
just hidden in the UI. `action=count` is Staff / Super Admin only.

### Files added / changed

**Added**

- `database/migration_conflicts.sql` - the `activity_conflicts` table plus the two
  sample clashing records.
- `includes/conflicts.php` - the detection engine: `findVenueConflicts()` (all
  clashes, with filters), `activityConflicts($id)` (one record's clashes),
  `blockingConflictsFor($id)` (only clashes that would block an approval),
  `summarizeConflicts()`, `conflictHotspots()`, `acknowledgedConflicts()` and
  `recordConflictOverride()`.
- `api/conflicts.php` - JSON endpoint (`check`, `count`).
- `conflicts.php` - the Staff / Super Admin page.

**Changed**

- `actions/update_status.php` - the approval guard, the override record, the
  "Conflict Overridden" audit entry and the notification fan-out.
- `assets/js/app.js` - `submitStatusChange(id, forceConfirm)` plus
  `renderConflictConfirm()`, which builds the confirmation dialog from the
  endpoint's `conflicts` array.
- `staff/activity_view.php`, `host/activity_view.php` - the conflict banner and,
  on the staff page, the inline status control that can pass `forceConfirm`.
- `includes/venue_availability.php` - computes the `double_booked` flag and
  `conflict_note` per half-day slot.
- `assets/css/style.css` - conflict banner / legend / hotspot styles, their
  `html[data-theme="dark"]` variants, and a print guard for the dialog actions.
- `includes/sidebar.php` - "Schedule Conflicts" nav entry (Staff + Super Admin).
- `database/ubmsconnect.sql` - so a **fresh** install gets the same table and
  sample pair without running the migration separately.

### How to test

1. Open `conflicts.php` as `staff` / `staff123`. The KPI strip should read **1**
   conflict, and the table should show *Robotics Club Orientation* clashing with
   *Marketing Summit Briefing* in **Conference Room A**, overlap **1 h 30 m
   (10:00 AM - 11:30 AM)**, severity **Confirmed**.
2. Narrow it: `?severity=potential` and `?venue=Training Room B` should both show
   the empty state; `?month=1` should too; `?severity=historical` is empty unless
   you add `&include_completed=1` and there is a Completed record in a clash.
3. Click **Export CSV** - you get `ubms-schedule-conflicts-<date>.csv` containing a
   SUMMARY block, a CONFLICT LIST block and a CONFLICTS BY VENUE block.
4. As `host` / `host123`, open *Robotics Club Orientation* (the record that host
   owns). The red banner appears, it does **not** link to `conflicts.php`, and
   `conflicts.php` itself returns **403** for a host.
5. As `staff`, try to approve a record that clashes: the dialog appears, and if you
   cancel, the status is unchanged. Confirm it, and the override is recorded - the
   "Approved anyway (audit trail)" table now appears on `conflicts.php` with your
   name and reason.
6. Approve a record that does **not** overlap anything: it goes through with no
   dialog at all.

### Verified (executed against this XAMPP install)

**Detection, filters and severity - `includes/conflicts.php` driven directly from
the PHP CLI**

- Default view finds exactly **1** clash: activities #16/#17 in Conference Room A,
  severity *Confirmed*, overlap `10:00:00-11:30:00` (1 h 30 m).
- Severity: `confirmed` 1, `potential` 0, `historical` 0 - and still 0 for
  `historical` when `include_completed=1` is forced, because neither record is
  Completed. `include_completed=1` otherwise 1.
- Venue filter: Conference Room A 1, Training Room B 0.
- Date filter: the seeded date 1; that same date plus Training Room B 0.
- School-year filter: the active year 1, a bogus id 0.
- Month filter: month 10 → 1, month 1 → 0.
- Organization filter (either side of the pair): the matching org 1, a bogus id 0.
- Per-activity: Robotics 1 → Marketing; Marketing 1 → Robotics; an unrelated
  record 0. `blockingConflictsFor()` 1, 1 and 0 respectively.
- **Back-to-back is not a clash.** A temporary `11:30-12:00` record in the same
  room did **not** pair with Robotics (09:00-11:30, which ends exactly when it
  starts), but **did** pair with Marketing (10:00-12:00), which it genuinely
  overlaps. So it produced exactly one clash, and that day's total went
  1 → 2 → back to 1 once the temporary row was deleted.

**Summary, hotspots and the override trail - second CLI harness**

- `summarizeConflicts()`: total 1, confirmed 1, potential 0, historical 0,
  1 venue affected, 1 day affected, total overlap 1 h 30 m.
- `conflictHotspots()`: Conference Room A - total 1, confirmed 1, 1 day.
- `recordConflictOverride()` inserted a row with `override_count` 1, the reason
  stored and `detected_by` resolved to the actor's full name. A second call for
  the **same pair** kept exactly 1 row and moved `override_count` to 2 with the
  new reason.
- **Graceful degradation**: with `activity_conflicts` renamed away,
  `acknowledgedConflicts()` returned an empty trail, `recordConflictOverride()`
  returned `false` after logging, and `findVenueConflicts()` still returned its 1
  clash - so detection never depends on the migration.

**Live HTTP against Apache, with real logins for all four accounts**

*Page and CSV*

- `conflicts.php` → 200 for Staff and Super Admin, **403 for both Event Hosts**.
- The rendered page contains the title, the "How a conflict is detected" panel,
  the Confirmed/Potential/Historical legend, all six filter controls, the export
  and print controls, both clashing record names, the `10:00 AM - 11:30 AM`
  overlap window, the KPI strip, the "Venues by number of conflicts" table and the
  "What the system does when a conflict is detected" policy card.
- `?severity=potential`, `?venue=Training Room B`, `?month=1&school_year=all` and
  `?severity=historical` each render the empty state; `?venue=Conference Room A`
  and `?severity=confirmed&include_completed=1` render the clash.
- `?export=csv` → 200 with `Content-Type: text/csv`, a
  `ubms-schedule-conflicts-<date>.csv` filename, 23 lines, and the SUMMARY /
  CONFLICT LIST / CONFLICTS BY VENUE sections.

*API*

- `check` (staff) → 200, total 1, blocking 1, the clash named as
  *Marketing Summit Briefing* / Approved, overlap `10:00 AM-11:30 AM`, "1 h 30 m",
  severity label "Confirmed".
- Conflict-free record → 0/0. Unknown id → **404**. `id=0` → **422**. No session
  → **401**.
- `count` → Staff 200 (total 1, confirmed 1, potential 0, historical 0, venues
  affected 1), Super Admin 200 (1), **both Event Hosts 403**.
- **Host own-record enforcement, tested in both directions**: `host` owns #16 and
  gets 200 on it while `host2` gets 403; `host2` owns #17 and gets 200 on it while
  `host` gets 403.
- Unknown action → `{"success":false,"message":"Unknown action."}` with HTTP 200,
  which matches the existing convention in `api/activities.php`,
  `api/notifications.php` and the `actions/manage_*.php` handlers.

*Availability panel and detail pages*

- `api/venue_availability.php?date=<seeded date>` reports Conference Room A's
  morning slot as `state: "occupied"` with both activity names,
  `double_booked: 1` and `conflict_note` = `Double-booked: "Robotics Club
  Orientation" & "Marketing Summit Briefing" (10:00 AM - 11:30 AM)`. Its afternoon
  slot carries no `double_booked` key at all, and an unaffected venue (Seminar
  Hall 1) is untouched.
- `staff/activity_view.php` on a clashing record renders the banner, names the
  other record, prints "overlaps by 1 h 30 m" and links to `conflicts.php`. The
  host equivalent renders the banner with the read-only wording and **no** link.
- Both pages render no banner at all for a record that does not clash.
- Both pages render no banner at all for a record that does not clash.

---

## 23. Google reCAPTCHA (Login + Registration)

The login form and the registration wizard are protected by **Google reCAPTCHA
v2 ("I'm not a robot" checkbox)** with **server-side verification**. The widget
is only cosmetic - every submission is re-checked against Google's `siteverify`
endpoint before any credential lookup or `INSERT` runs.

### What was added

| File | Purpose |
|------|---------|
| `config/env.php` | Minimal `.env` loader (no composer dependency). Reads the project-root `.env`; real process environment variables win over file values. |
| `config/recaptcha.php` | Defines `RECAPTCHA_ENABLED`, `RECAPTCHA_SITE_KEY`, `RECAPTCHA_SECRET_KEY`, the verify/script URLs and the 5 s timeout. |
| `includes/recaptcha.php` | `recaptchaVerify()` (server-side check), `recaptchaWidget()` (renders the checkbox field, hidden until the page reveals it), `recaptchaScriptTag()` (loads `api.js`). |
| `.env.example` | Documented template for every variable - copy to `.env` and edit. |
| `.env` | Local keys (currently Google's official **test keys**). |
| `.htaccess` | Denies HTTP access to `.env` and `.env.example` (verified: requesting `.env` returns **403**). |
| `assets/css/style.css` | `.recaptcha-field` spacing + a scale-down on very narrow phones. |

`login.php` and `register.php` each call `recaptchaVerify()` inside their
existing `POST` block: a failure only adds an error message, so the untouched
"if no errors, proceed" gates still block sign-in / account creation exactly as
before.

### Configuration (`.env`)

```ini
RECAPTCHA_ENABLED=true
RECAPTCHA_SITE_KEY=<your site key>
RECAPTCHA_SECRET_KEY=<your secret key>
```

- The feature is active only when it is **enabled AND both keys are present**.
  A half-configured deployment fails *safe* into "no captcha" rather than
  showing every visitor an unsolvable widget and locking them out of login.
- `RECAPTCHA_ENABLED=false` removes the widget and skips verification, leaving
  the pages visually identical to the pre-reCAPTCHA version - useful for local
  debugging.
- The keys currently in `.env` are Google's documented **test keys**, so the
  widget shows a "testing purposes only" notice and verification succeeds for
  any token. Swap in real keys from
  <https://www.google.com/recaptcha/admin/create> (choose **reCAPTCHA v2 ->
  "I'm not a robot" Checkbox**) and add your domain to that key's domain list
  before deploying.

### Where the widget appears

- **Login**: hidden until it is actually needed. `recaptchaWidget()` now outputs
  a *hidden* field whose inner box has **no** `.g-recaptcha` class, and `api.js`
  is loaded with `render=explicit`, so nothing at all is drawn on page load or
  while the visitor types their credentials. The widget is revealed and
  rendered on the visitor's **first attempt to sign in** - the moment the check
  becomes required - and that attempt (plus any until the box is ticked) is
  held client-side. `onload=ubmsLoginRecaptchaReady` draws it if the library
  finishes loading after that first attempt, and a `widgetId` guard renders
  exactly one widget. Purely presentational: server-side verification in
  `recaptchaVerify()` is unchanged and still rejects any token-less POST.
- **Registration**: the library is loaded with `render=explicit` because the
  widget sits inside **Step 2** of the wizard, which starts hidden - an
  auto-rendered widget would be sized while invisible and appear blank. The
  wizard calls `grecaptcha.render()` the first time Step 2 is shown
  (`ubmsRenderCaptcha()`), and Google's `onload=ubmsRecaptchaReady` callback
  covers the case where the library finishes loading *after* that moment. A
  failed submit re-opens on the step with the error, so the widget is always
  drawn when it is actually needed.

### Error handling

| Situation | Behaviour |
|-----------|-----------|
| Box not ticked (no token) | Login holds the submit locally until the box is ticked; any POST that does reach the server without a token (registration, scripted login) is rejected with *"Please tick the \"I'm not a robot\" box to continue."* |
| Expired / already-used token | Google's error code mapped to a friendly message; form data is preserved. |
| Wrong secret key | Logged via `error_log` as a configuration error; visitors get a neutral "temporarily unavailable" style message. |
| Network failure / timeout (5 s) | Treated as a failure, not a pass - logged, user sees a friendly message. |
| Missing or empty keys / disabled | Verification and widget are skipped entirely; local dev works with no Google account. |

### Verification (live HTTP against Apache)

- `login.php` and `register.php` → **200**; login serves a **hidden**
  `#recaptchaField` + `#recaptchaBox[data-sitekey]` with `render=explicit` and
  `onload=ubmsLoginRecaptchaReady` (nothing visible on initial load); the
  `render=explicit`, `#recaptchaBox[data-sitekey]` and `onload=ubmsRecaptchaReady`
  markers are present on registration. Each page has exactly **one** `api.js`
  include and **one** widget box, with **no** auto-render `g-recaptcha` class -
  so no duplicate widgets can appear.
- `GET /.env` → **403**.
- Login **without** a token (valid credentials) → rejected with the captcha
  message, no redirect, no session.
- Login **with** a token → lands on the dashboard with a session cookie
  (`staff/dashboard.php` for Staff).
- Login with a token but a **wrong password** → the normal generic
  "Invalid email/username or password" message (captcha passed, auth still
  enforced).
- Registration **without** a token → rejected, **no row inserted**.
- Registration **with** a token → account created, then usable to sign in
  (round trip confirmed, test row cleaned up afterwards).
- Empty-token verification returns `missing-input-response` in **0 ms**
  (no network round trip wasted).
- The verifier performs a **real call to Google**: with a deliberately bogus
  secret, `siteverify` answers `invalid-input-response`/`invalid-input-secret`
  - proving genuine Google verification rather than a local stub.


*The approval guard, end to end*

A temporary **Pending** `09:15-09:45` record in Conference Room A (owned by
`host2`, so it overlaps Robotics but not Marketing) was created and removed again:

- POST **without** `confirm_conflict` → HTTP 200,
  `{"success":false,"conflict":true}`, `venue_name` "Conference Room A", a message
  naming *Robotics Club Orientation* and its 9:00 AM-11:30 AM slot, one conflict
  with overlap `9:15 AM-9:45 AM` and severity "Potential". The transaction rolled
  back: the status was **still Pending**, and neither an `activity_conflicts` row
  nor an `activity_status_logs` row existed afterwards.
- POST **with** `confirm_conflict=1` → `{"success":true}`, `Pending → Approved`,
  `conflicts_recorded: 1`, a status-log row, one `activity_conflicts` row
  (`venue_id` 1, primary 21, other 16, overlap `09:15:00-09:45:00`,
  `override_count` 1, `detected_by` 2 = `staff`, reason "Live verification run"),
  and **two** audit entries: `Status Changed | Pending | Approved` and
  `Conflict Overridden | No conflict recorded | 1 conflicting activity record(s)
  approved anyway - reason: Live verification run`.
- **5 notifications** were created: `host2` (the activity's own host), `host` (the
  Event Host whose record was overlapped), `staff2` and `Jaypee` (the other active
  Staff members), and `superadmin`. The Staff member who performed the action was
  correctly **not** notified, and the first message reads `Your activity "ZZ Guard
  Test Activity" status changed to Approved.`
- `conflicts.php` then showed the "Approved anyway (audit trail)" section with the
  reason and the actor's name, and `action=count` moved from 1 to 2.
- Overriding the same pair again kept exactly **1 row** and moved `override_count`
  to 2 with the new reason.
- Approving a record with **no** overlap (moved to Training Room B, 08:00-09:00)
  succeeded with `conflicts_recorded: 0` and added no override row - no false
  positives.
- After cleanup the database was back to its exact starting counts:
  `activities=17, notifications=4, audit_logs=25, activity_status_logs=10,
  activity_conflicts=0`, and the page showed a single conflict again with no
  audit-trail section. No temporary row, notification or audit entry remains.

**One real defect found by this verification, and fixed**

The acknowledgement trail was cached in a `static` variable that was never
invalidated, so within a single request an override written *after* the trail had
already been read stayed invisible. This was invisible in normal page loads (no
request both writes and re-reads) but wrong, and it broke the override-trail
assertions. Fixed in `includes/conflicts.php`: `acknowledgedConflicts()` now takes
`$refresh`, and `recordConflictOverride()` calls `acknowledgedConflicts(true)`
right after it writes. Re-running the harness then reported `override_count` 1
after the first insert, 2 after the second, and the override correctly marked as
acknowledged on the page.

(Apart from that, several early failures in the verification harness itself were
my own test bugs, not application bugs - notably a PowerShell variable named
`$host` silently colliding with the read-only automatic `$Host`, and a date
computed as `CURDATE() + 8` when the sample rows were seeded relative to the day
the migration first ran. Both were corrected in the harness, not the app.)

**Regression checks**

- `php -l` clean on all nine touched PHP files.
- Unaffected pages still return 200 for the role that owns them: `reports.php`,
  `staff/activities.php`, `staff/dashboard.php`, `staff/calendar.php`,
  `staff/venues.php`, `staff/logs.php`, `staff/profile.php`, all seven
  `superadmin/*` pages tested, and all five `host/*` pages tested.
- `reports.php` and both dashboards are **unaffected by the sample rows** - the
  sample pair is dated 8 days after the migration ran, so it never lands in
  "Today's Activities" and never changes a KPI count.
- `assets/css/style.css` braces still balance after the added conflict rules.

### Known limitations (explicit, not claimed otherwise)

- **Detection is a read-time query, and prevention happens only at approval
  time.** Two records that were already Approved before this feature existed will
  simply be reported as a *Confirmed* clash rather than blocked retroactively.
  That is the intended behaviour, but it does mean the page can show a conflict
  that nobody created through the new dialog.
- A clash is only detected **between two records at the same venue on the same
  date, using the stored start/end times**. It knows nothing about setup or
  cleanup time between events, travel time between venues, or whether the venue
  is actually big enough for the attendees.
- **`reason` is optional** and capped at 255 characters. A Staff member can
  complete an override without typing anything, so the audit trail can legitimately
  read "no reason given".
- The two sample activities are **demo data**, dated 8 days after the migration
  first ran. Delete them (or change their times) and the conflict disappears; the
  page falls back to its empty state. Nothing else depends on them.
- **`activity_conflicts` is deliberately optional.** If the migration has not been
  run, detection, the banners, the availability badge and the approval guard all
  still work - only the override trail is unavailable, because
  `acknowledgedConflicts()` returns nothing and `recordConflictOverride()` logs and
  returns `false` instead of letting the failure block an approval.
- `conflicts.php` reports clashes across **all** dates by default, including past
  ones. The date, month and school-year filters are what narrow it; there is no
  "upcoming only" toggle.
- The detector compares activities pairwise, so a venue booked by three overlapping
  records is reported as three pairs. That is intentional (each pair is a distinct
  double booking) but it means the conflict *count* is a pair count, not a count of
  venues or events.

---

## 22. Super Admin Scope Tightening (Redundant Overview Pages Removed)

Super Admin is an **administrative** role (accounts, approvals, audit trail,
school years, institutional records). It previously *also* carried four
read-only pages that were near-verbatim duplicates of pages UBMS Staff already
owns, so the role was shadowing Staff's operational views for no functional
gain. Those four pages and every reference to them are now gone. **Nothing
Staff do changed** - no Staff page, guard, or workflow was touched.

### The role model after this change

| Role | Owns |
|------|------|
| **UBMS Staff** | All operational monitoring: activities, calendar, venues, utilities, maintenance, activity logs, reports, schedule conflicts |
| **Event Host** | Read-only view of their own activities / venues / utilities |
| **Super Admin** | Accounts, Staff registration approval, audit trail, school years, institutional reference data, reports, schedule conflicts |

### Removed (files deleted)

```
superadmin/activities.php     was: "Activity Overview"     (read-only duplicate)
superadmin/venues.php         was: "Venue Overview"        (read-only duplicate)
superadmin/utilities.php      was: "Utility Overview"      (read-only duplicate)
superadmin/maintenance.php    was: "Maintenance Overview"  (read-only duplicate)
```

`staff/{activities,venues,utilities,maintenance}.php` and every Host page are
untouched. Each deleted file carried its own inline JavaScript (the add/edit
modals), so no shared script was orphaned.

### Changed - navigation

- `includes/sidebar.php` - the four Super Admin nav entries are removed. The
  Super Admin sidebar is now: Dashboard, User Management, Pending
  Registrations, Audit Logs, Schedule Conflicts, Reports, School Years,
  Institutional Records, Notifications, Settings, Profile, Logout.
- **Dashboard is kept and is still the post-login landing page** (`index.php`
  routes `super_admin` to `superadmin/dashboard.php`). Reports and Schedule
  Conflicts are kept too - they are genuinely shared Staff + Super Admin pages,
  not duplicates.

### Changed - notifications retargeted (not removed)

Super Admin still receives the same notifications; only the link target moved,
because it used to point at the deleted pages. `superadmin/audit_logs.php`
supports `?action_filter=<exact action label>`, so each notification now opens
the audit trail already filtered to the event that triggered it:

| Action file | Notification | New link target |
|-------------|--------------|-----------------|
| `actions/manage_venue.php` | "New venue added…" | `audit_logs.php?action_filter=Added Venue` |
| `actions/update_venue.php` | "Venue … status changed…" | `audit_logs.php?action_filter=Updated Venue Status` |
| `actions/manage_utility.php` | "New utility added…" | `audit_logs.php?action_filter=Added Utility` |
| `actions/update_utility.php` | "Utility … status changed…" | `audit_logs.php?action_filter=Updated Utility Status` |
| `actions/add_maintenance.php` | "New maintenance record added…" | `audit_logs.php?action_filter=Added Maintenance Note` |

Each action label is written by the `logAudit()` call a couple of lines above
its `notifyRole()` call, in the same file. If a label is ever renamed the link
degrades to the unfiltered log rather than breaking.

### Changed - Schedule Conflicts detail link

`conflicts.php` previously sent Super Admin to the deleted
`superadmin/activities.php`, and Staff to `staff/activity_view.php?id=N`.
`staff/activity_view.php` is `requireRole('staff')` **and** contains the
approve/reject control, so widening it would have changed a Staff page and
shown Super Admin a button that `actions/update_status.php`
(`requireRoleApi('staff')`) would reject anyway.

Instead the page now computes one flag:

```php
$openRecord = !isSuperAdmin();
$detailLink = '/UBMSConnect/staff/activity_view.php?id=';
```

- **Staff** - the activity name is still a link to the full record.
- **Super Admin** - the name renders as plain text. Nothing is lost: the
  conflict table already shows the status, overlap window, host and organization
  for both records, which is everything needed to judge a clash.

The "confirmed double booking" notice no longer says "(or Activity Overview)";
it now reads "UBMS Staff can open a record below and change its status to
resolve it."

### Changed - write endpoints no longer accept Super Admin

Deleting the pages would have been cosmetic if the endpoints still accepted
them. Three actions were `requireRoleApi(['staff', 'super_admin'])` and are now
`requireRoleApi('staff')`, matching the fact that their only remaining callers
are the Staff pages:

- `actions/manage_venue.php` (create / edit / deactivate venue)
- `actions/manage_utility.php` (create / edit / deactivate utility)
- `actions/manage_maintenance.php` (clear one / clear all maintenance notes)

`actions/update_venue.php`, `actions/update_utility.php`,
`actions/update_status.php` and `actions/add_maintenance.php` were already
Staff-only and were not touched.

### Files changed in this round

**Deleted:** `superadmin/{activities,venues,utilities,maintenance}.php`.

**Changed:** `includes/sidebar.php`, `conflicts.php`,
`actions/{manage_venue,update_venue,manage_utility,update_utility,
add_maintenance,manage_maintenance}.php`.

### How to test

1. Log in as `superadmin` / `SuperAdmin123`. You land on the dashboard as
   before, and the sidebar has **no** Activity / Venue / Utility / Maintenance
   Overview entries.
2. Request `superadmin/activities.php` (or the other three) directly - you get
   the web server's 404, because the files no longer exist.
3. Open **Reports** and **Schedule Conflicts** - both still load for Super
   Admin. On Schedule Conflicts, confirm the activity names in the conflict
   table are **plain text**, not links.
4. Log in as `staff` / `staff123` and confirm the reverse: Staff still has
   Activities / Venues / Utilities / Maintenance, and on `conflicts.php` the
   activity names **are** links that open `staff/activity_view.php`.
5. As Staff, add a venue, add a utility, and add a maintenance note. Each still
   succeeds, and the resulting Super Admin notification now opens Audit Logs
   filtered to that action instead of a dead page.
6. As `superadmin`, POST directly to `actions/manage_venue.php` - it is now
   rejected, because the endpoint no longer accepts the Super Admin role.

### Known limitations (explicit, not claimed otherwise)

- **Super Admin has no per-activity detail page at all now.** That is the
  direct consequence of removing Activity Overview. Reports and the conflict
  table carry the activity information Super Admin needs; drill-down into a
  single record is a Staff action, by design.
- The Super Admin venue / utility / maintenance notifications point at **Audit
  Logs**, which is the record *of* the change rather than the changed object.
  It is a deliberate substitute for a page Super Admin no longer has.
- Super Admin can no longer create or edit venues or utilities through the UI
  **or** the API. If that capability is ever wanted back, the guard in
  `actions/manage_{venue,utility}.php` is the single line to revisit.

## 24. Utilities Monitoring Upgrade (Venue-Level Parity)

The Utilities page previously offered only a plain table (name, location,
quantity, status). This round brings it to the same **monitoring quality** as
the Venues page while keeping utility data utility-appropriate — it is *not* a
copy of the Venues page with the nouns swapped.

### What was added

| Area | Detail |
|---|---|
| **Photos** | Same implementation as venues: upload/replace inside the create/edit modal (`type="file" id="uImage"` → `FormData.append('utility_image', …)`), stored in `assets/uploads/utilities/`, "No photo yet" placeholder in the table and the details modal. The photo code itself is **shared**, not duplicated: both `manage_venue.php` and `manage_utility.php` now call the same helpers in `includes/image_uploads.php` (`imageUploadError()`, `saveUploadedImage()`, `deleteUploadedImage()`). |
| **Schedule availability** | New `includes/utility_availability.php` + `api/utility_availability.php`, mirroring the venue availability API in shape but **reusing the same `activities` scheduling data** — no second booking system. A utility linked to a location (`activities.location`) is marked *Occupied* during that activity's Morning/Afternoon window; otherwise it follows its own stored status. The same `double_booked` / `conflict_note` decoration from Schedule Conflicts appears here too (Conference Room A's 09:00–11:30 vs 10:00–12:00 clash shows identically on the venue and utility panels). |
| **Details modal** | `includes/utility_details.php` — photo (or placeholder), status, location, quantity **only when the utility actually has one** (the row is omitted when `quantity` is NULL — no fake capacity), today's linked activity, description. |
| **Table columns** | `PHOTO · UTILITY · BUILDING/LOCATION · QUANTITY · TODAY'S ACTIVITY · STATUS · UPDATE STATUS · ACTIONS`. Columns are omitted rather than faked when meaningless: Quantity renders `—` when NULL, and there is intentionally **no** Capacity/Fee column. |
| **Status controls** | Existing utility statuses **unchanged** (`Operational / Maintenance / Unavailable`), with badges consistent with the venue badges. `actions/update_utility.php` saves status changes + writes the audit entry + notifies Staff and Super Admin (links to Audit Logs, per §22). |
| **Actions** | View details / Edit / Activate-Deactivate exactly as before, now alongside the photo-aware create/edit modal (optional `Quantity`, "Leave blank if not applicable"). |
| **Host view** | `host/utilities.php` got the same read-only upgrade (photo, availability, details) — **no management controls**, and `POST actions/manage_utility.php` still returns the Event Host permission error. |

### Files

**New:** `includes/image_uploads.php`, `includes/utility_availability.php`, `includes/utility_details.php`, `api/utility_availability.php`, `database/migration_utility_images.sql`
**Changed:** `staff/utilities.php`, `host/utilities.php`, `actions/manage_utility.php`, `actions/update_utility.php`, `actions/manage_venue.php` (shared-photo refactor), `includes/functions.php`, `database/ubmsconnect.sql`

### Database

One nullable column — `utilities.image_path VARCHAR(255)` — added by
`migration_utility_images.sql` (idempotent) and mirrored in
`ubmsconnect.sql`. No new tables, no scheduling duplication; `quantity`
stays `NULL` when not applicable instead of defaulting to a fake number.

### Verification (executed)

- Page loads; **all 7 original utilities** present with original statuses; PHOTO/QUANTITY/Schedule-Availability sections render; badges for all three statuses.
- Photo upload live-tested (file landed in `assets/uploads/utilities/`, rendered in the table, then cleaned up); "No photo yet" shows for rows without photos.
- Status update saved; invalid status, negative quantity (`-5`), non-numeric quantity, and blank name all rejected with friendly messages; Edit succeeded; Deactivate hid the row / Reactivate restored it; unknown action rejected.
- Availability API: 9 rows before cleanup, correct Morning/Afternoon states, `double_booked` parity with the venue API verified, bad date rejected.
- **Venues page, Activities, Calendar, Conflicts, Reports** all still render (regression pass).
- Host page: read-only, `mgmtControls=False`, POST → permission error.
- All 8 touched/new PHP files pass `php -l`; test rows, test photo and related audit/notification entries removed afterwards; utility table restored to the original 7 records.
