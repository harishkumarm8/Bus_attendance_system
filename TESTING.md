# Verification results

Verified on local XAMPP PHP 8.1.25 and MariaDB 10.4.32.

## Passed

- Syntax validation of all four PHP files (`php -l`).
- JavaScript syntax (`node --check js/script.js`).
- SQL imported successfully into local `bus_attendance`.
- Exactly nine student columns; primary student ID and unique attendance student/date key inspected in MySQL.
- 28 HTTP/database integration checks via `python tests/verify.py`: login rendering, headers, HttpOnly cookie, anonymous authorization, GET mutation rejection, CSRF rejection, initial admin setup, password hashing, login, session regeneration, role separation, empty directory/search/report states, CSV content/access, invalid date rejection, missing cloud configuration, failed sync preserving the empty database, 404 handling, logout, repeat setup rejection, login rate limiting, schema and PHP warning checks.
- The test database and its temporary admin were removed. No student or faculty records were inserted. The application database contains no initial admin; use the documented secure setup flow.

## Not yet verified

- Live cloud schema, credential mappings, faculty password format and class year mapping: not supplied or accessible.
- Real cloud connection, synchronization, preservation of existing bus assignments on a real re-sync, and eligible/ineligible faculty login: require configured credentials, verified mappings and a host supporting remote MySQL plus TLS. InfinityFree free hosting blocks external XAMPP access.
- Real student editing, attendance entry/editing across multiple dates, concurrent duplicate writes and staff history: require actual synchronized students and eligible faculty. Unique keys and transactional upserts are implemented but these populated workflows have not been executed. No fabricated student/faculty data was used.
- Visual desktop/mobile inspection: in-app browser navigation to the local preview timed out. Responsive CSS is implemented but rendered appearance at 390px has not been verified.

## Acceptance checklist after cloud configuration

1. Inspect real cloud column names and existing ERP authentication code. Configure the verified mappings and TLS CA in `config.php`.
2. Sync eligible records twice. Confirm the student count is stable and that edited bus/drop assignments survive the second sync. Confirm unknown academic years remain NULL.
3. Verify transport-ineligible faculty cannot log in, an eligible faculty can, and eligibility revocation blocks subsequent staff requests.
4. Activate a real student's bus/drop assignment. Mark present today, switch to absent, then mark present on a different past date. Confirm two rows total for that student and distinct date results; repeat the same-date action to ensure it updates one row.
5. Check two eligible faculty editing the same student/date. Confirm one row remains and `staff_user_id` is the latest editor.
6. Check inactive students cannot be marked through either the UI or a crafted form. Check staff cannot open `admin.php` or export reports.
7. Search by real name and register number; test pagination with more than 25 real students. Compare report counts and CSV against local attendance rows.
8. Review desktop and 390px layouts, modal focus/closing, mobile menu, loading feedback and keyboard navigation in a browser.
9. Disconnect cloud access and confirm login/attendance fail clearly without writes. Restore access and retry.
