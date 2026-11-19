## Separate bus page and automatic filters

Use Admin → Add bus to enter a bus number, driver name, phone number, and 1–10 drop points in route order. The bus directory shows these details and assigned faculty, and allows editing existing buses. Bus numbers cannot be renamed here. Assign faculty through Faculty management. Assigned staff see their bus, driver contact and drop points on the staff dashboard.

Search updates 500 ms after typing stops. Dropdown and date filters apply on selection. Search/Apply filters buttons have been removed from student, faculty, report and staff lists. Filters still use server-side prepared queries and pagination.

Existing installations apply `database/upgrade_bus_details.sql` after `upgrade_faculty_buses.sql`. This installation has already been upgraded. Exactly four PHP files are retained; the separate page is `admin.php?action=add_bus`.

Verified 10 stops save correctly, an 11th is rejected without changing saved data, and assigned faculty can see driver/route details. Temporary verification data was removed.

## Faculty management and bus dropdowns

Admin → Faculty management lists the ERP faculty directory with search and pagination. Admins can enable/disable application access and assign a bus. These settings live in local `faculty_access`; ERP faculty records are not modified. All faculty are enabled by default until explicitly disabled. Disabling blocks new logins and the next request from an existing session. Bus assignments are informational and do not limit attendance scope.

Add real bus numbers using the Bus numbers form on Student management or Faculty management. Dropdowns read the shared local `buses` table. Existing student bus numbers are included by the migration. Student bus status supports Active, Inactive, and Not available; only Active students with a drop point name can be marked for attendance.

Faculty headers display the current ERP first/last name and faculty ID. Existing installations apply `database/upgrade_faculty_buses.sql` once; this installation has already been upgraded. Fresh installations use `database/bus_attendance.sql`.

Verified status saving, bus creation and selection, faculty bus assignment, name display, and disabled faculty session/login rejection. Verification restored original settings and removed its temporary bus.

## Updated college mappings

All 75 faculty records in local `erp.erp_faculty` can log in with their unique `f_id` only; transport status is no longer a login restriction. Admin passwords remain required. Staff sessions check that the faculty record still exists on each request.

Department uses digits 7–9 of the 12-digit register number: 243 AI & DS, 104 CSE, 105 EEE, 106 ECE, 114 MECH, 103 CIVIL, 631 MBA, 405 M.E. CSE, 401 M.E. AE, 411 M.E. PSE. First-year UG students display H&S per the supplied semester note. PG departments retain their program name. Unknown register codes remain unassigned. Semesters 1/2, 3/4, 5/6, 7/8 map to years 1, 2, 3, 4 respectively.

These rules supersede older eligibility notes below.

# Current local ERP setup — 19 November 2026

The application now reads students, faculty and classes from the imported local `erp` database using `ERP_DB` in `config.php`. Attendance and bus assignments remain in `bus_attendance` using `LOCAL_DB`. No remote connection or TLS CA is needed for the loopback MySQL connection. Remote hosts still require verified TLS.

299 records with `stu_transport='yes'` were synchronized. The existing demo record was preserved. New records start inactive; assign their bus and drop point name and activate the bus before attendance entry.

Class mapping: `erp_class.cls_id` joins `erp_student.cls_id`. `cls_sem` values 1–8 (including section suffixes such as 8a) map to study year using ceiling(semester / 2). Passed-out or unrecognized values remain unassigned. Imported eligibility is a snapshot; later changes to the original hosted ERP require an updated local import.

Faculty login uses `f_id` only, as requested. Leave the admin password field blank for staff. The ID must match exactly one record with `f_transport='yes'`. Anyone knowing an eligible faculty ID can use its staff account. Admin login still requires its password. Legacy password mapping settings are not used by this login flow. Admin credentials and attendance records are unchanged.

The original cloud installation notes below are historical; use ERP_DB and the local imported database for this installation.

---

# Campus Transit — Bus Attendance

PHP 8.1+ / PDO MySQL / MySQL 5.7+ or MariaDB 10.4+ / Bootstrap 5.
Exactly four PHP files. No seeded student, staff or admin accounts.

## Cloud deployment blocker

InfinityFree free MySQL databases do **not** allow connections from local XAMPP. See https://forum.infinityfree.com/t/connecting-to-mysql-from-an-external-application/49339 . A correct password cannot remove that restriction. This application needs an authorized, remotely reachable MySQL host with verified TLS. Obtain a remote-access hosting arrangement before testing cloud features. It does not introduce a proxy or modify your cloud database.

The supplied requirements identify `erp_student` fields and faculty `f_id`/`f_transport`, but do not contain the faculty credential columns, password format, or class schema. These are intentionally unconfigured. Cloud login and actual-year lookup cannot be claimed verified until the real schema and connection are available.

## XAMPP installation

1. Install XAMPP with PHP 8.1 or newer. Start Apache and MySQL in the XAMPP Control Panel.
2. Copy this entire folder to `C:\xampp\htdocs\bus_attendance`.
3. Open `http://localhost/phpmyadmin`. Import `database/bus_attendance.sql`. It creates `bus_attendance` and four tables without inserting any people.
4. Edit `LOCAL_DB` in `config.php` for your local database. The default is XAMPP's local root account with an empty password; use a dedicated local database user for deployment.
5. Set `CLOUD_DB` credentials in `config.php` only. The supplied host/database are already populated. Use the actual database username from hosting control panel. Configure the host's CA certificate path in `ssl_ca`; the connection requires certificate validation and negotiated TLS. Do not commit credentials or expose this file as plain text.
6. Generate a setup secret using PowerShell: `C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32));"`. Copy the result into `SETUP_TOKEN` in `config.php`.
7. Open `http://localhost/bus_attendance/login.php?action=setup`. Enter the secret, your chosen username and a new password of 12–72 bytes. Setup uses `password_hash()` and an atomic singleton admin row to prevent duplicate initial accounts.
8. Clear `SETUP_TOKEN` after setup. Log in at `http://localhost/bus_attendance/login.php` with your admin username and password. The role is detected automatically.
9. Open **Synchronize students → Inspect schema** once remote cloud access works. This lists table/column names only. Alternatively inspect the actual cloud database through its phpMyAdmin. Set `FACULTY_USERNAME_COLUMN` and `FACULTY_PASSWORD_COLUMN` to verified existing columns. Review the ERP authentication code to identify its storage format; set `FACULTY_PASSWORD_FORMAT` explicitly. Supported formats: `password_hash`, `md5`, `sha256`, `plaintext`. Legacy formats are compatibility options only; there is no automatic detection or fallback. If the ERP uses another scheme, implement its verified algorithm in `verify_faculty_password()` before enabling login.
10. Identify the class table keyed by student `cls_id`, its unique identifier column, and actual academic-year column. Set `CLASS_TABLE`, `CLASS_ID_COLUMN`, `CLASS_YEAR_COLUMN`. Leave all three empty if unknown; the local year will remain NULL. Never use the numeric class ID as the year.
11. Click **Synchronize students**. Only `stu_transport='yes'` records are upserted. Existing bus fields and inactive/active choices survive. Other local students are retained. New students start inactive and unassigned.
12. Use **Student management → Edit** to assign bus numbers/drop points and activate each student's assignments.
13. Log out using the button. Sign in with an actual eligible faculty account. The cloud must be available, `f_transport='yes'`, and verified credential mappings configured.
14. Select the attendance date and mark Present or Absent. Click the other status to correct it. A unique student/date key ensures editing that day never creates duplicates or changes another day.
15. View staff history or admin reports. Admin CSV export includes the selected date and search filter.

Bootstrap, Bootstrap Icons and Manrope load from HTTPS CDNs, so internet access is required for their assets. Local CSS, JavaScript and server forms remain separate. If using PHP's development server instead of Apache, `.htaccess` does not apply; use it only on localhost and never expose the project directory publicly.

## Behavior and security

- Cloud SQL is read-only: SHOW metadata and SELECT only. Restrict the cloud DB user to SELECT where hosting supports it.
- Faculty eligibility is rechecked on every staff request, including every attendance update. Cloud outages fail closed. Faculty IDs are saved as the latest attendance editor.
- All eligible staff share attendance entry for active students because no faculty-to-bus authorization source was specified. Staff history shows records they last edited. There is no invented faculty bus mapping.
- The nine-column students schema cannot store historical eligibility. The admin total is honestly labeled synchronized students, and includes retained records. Staff eligibility is based on active local bus/drop assignments, as requested. Review retained students manually when cloud eligibility changes.
- Bus/drop status belongs to each student's assignment. Distinct bus counts can overlap if students on one bus have different assignment states. There is no separate bus fleet master table in the requested model.
- Reports preserve attendance dates and statuses but display current student/transport details. History is not an immutable audit log; the required attendance model stores the latest editor only.
- State changes and logout require POST plus a session CSRF token. Role checks, session regeneration, 30-minute inactivity expiry, secure cookies on HTTPS, output escaping, prepared SQL, input limits and CSV formula protection are included.
- Login/setup attempts are limited to 10 per source IP per 15 minutes in the database. This intentionally shares a limit across sessions; campus users behind one IP may share that limit. Expired entries can be periodically removed from `login_attempts` by your local DBA.
- No future attendance dates are accepted. Application timezone is Asia/Kolkata.

## Files

- `config.php`: settings, connections, security and shared page helpers.
- `login.php`: setup, admin/faculty login and POST logout.
- `admin.php`: directory, sync, metadata inspection, edits, reports and CSV.
- `staff.php`: attendance entry, edits and personal history.
- `database/bus_attendance.sql`, `css/style.css`, `js/script.js`.

See TESTING.md for verified checks and remaining cloud acceptance tests.
