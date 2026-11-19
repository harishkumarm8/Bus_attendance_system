# Project memory

## Environment
Workspace: C:/Users/haris/Pictures/Bus attend

Installed application: C:/xampp/htdocs/bus_attendance

URL: http://localhost/bus_attendance/

## Decisions
- Four PHP files only; additional SQL/CSS/JS/docs are allowed.
- Four cloud tables: erp_class, erp_student, erp_faculty (read-only references), Attence (all mutable app data).
- All seven logical app entity types (students, attendance, admins, buses, bus_drop_points, faculty_access, login_attempts) are stored as typed JSON rows in Attence.
- APP_DB and ERP_DB both target the erp database on TiDB Cloud (same host, same DB, different session modes).
- ERP connection is SET SESSION TRANSACTION READ ONLY; app connection is read-write.
- TiDB TLS connection verified on 19 November 2026 with ISRG Root X1 certificate.
- Attence schema is in database/attence_schema.sql — must be run once on the TiDB erp database before first app use.
- Do not remove local source databases as part of cutover.
- Credentials live only in config.php; never in documentation or committed files.

## Functional agreements
- Staff login uses f_id only; all existing faculty permitted unless access is disabled in faculty_access (Attence record).
- Faculty names are f_fname plus f_lname. Admin password login remains.
- Department codes: 243 AI & DS, 104 CSE, 105 EEE, 106 ECE, 114 MECH, 103 CIVIL, 631 MBA, 405 M.E. CSE, 401 M.E. AE, 411 M.E. PSE. First-year UG displays H&S.
- cls_sem 1/2 → Year 1, 3/4 → 2, 5/6 → 3, 7/8 → 4. Recognized section suffixes allowed; passed-out values unassigned.
- Up to ten bus stops; multiple staff per bus, one bus per faculty. Staff assignment is informational, not an attendance scope restriction.
- Student drop-point status was removed from the UI; the legacy column remains in the JSON payload for schema compatibility.
- Royal theme is presentation-only. Preserve functionality during visual updates.

## Migration snapshot (19 Nov 2026)
- erp_class: 48 rows
- erp_faculty: 75 rows
- erp_student: 1,247 rows
- Attence students: 299
- Attence attendance: 2
- Attence admins: 1
- Attence buses: 1
- Attence faculty_access: 2
- Attence bus_drop_points: 3
- Attence login_attempts: 0
Full-row SHA-256 checksums recorded in database/tidb_migration_report.json.
