# Architecture

The filename follows the requested spelling.

Browser → Apache/PHP on XAMPP → PDO MySQL protocol over verified TLS → TiDB Cloud.

## Components
- config.php: database configuration, session authentication, role checks, helpers, shared layout.
- login.php: admin setup/login, faculty ID lookup, logout.
- admin.php: synchronization, student/faculty/bus administration, reports and PDF data.
- staff.php: attendance, history and assigned bus details.
- css/style.css: college visual theme; js/: interaction and locally bundled PDF generation.

## Databases
`bus_attendance`: admins, students, attendance, buses, bus_drop_points, faculty_access, login_attempts.

`erp`: erp_student, erp_faculty, erp_class. Read-only reference tables; synchronization copies eligible identity/academic information into application students without changing reference records.

## Boundaries
Server-side credentials only; prepared SQL values; identifiers come from validated configuration. Admin and faculty routes require role checks. State mutations use POST and CSRF. Remote database failure must fail visibly without writing to a different database. Browser session/local storage must not persist business records.

PHP session cookies and PHP session files remain authentication infrastructure on the web server, not the business database. The local source databases are retained as pre-cutover copies and must not be used after cutover unless an explicit rollback is authorized.

## Migration status
TiDB TLS connection verified. Data migration and cutover pending the table-scope confirmation and subsequent verification. See task.md and memory.md for updates.
