# Product requirements — Grace College bus attendance

## Purpose
Administrators manage campus transport and faculty access. Faculty record daily student attendance. Preserve the royal purple college design and existing functionality.

## Storage decision
TiDB Cloud becomes the authoritative store after migration verification. Do not use local MySQL or browser storage as a fallback for business records. Keep separate `bus_attendance` (application writes) and `erp` (reference reads) schemas. Do not use TiDB's `sys` schema for application tables.

## Existing behavior to preserve
- Exactly four PHP files: config.php, login.php, admin.php, staff.php.
- Admin password login; faculty ID-only login for all existing faculty unless disabled by an administrator.
- Synchronize only ERP students whose stu_transport is yes. Preserve local transport assignments during synchronization.
- Student table retains exactly nine columns. Year derives from cls_sem; department derives from the college register-code mapping, including first-year UG H&S.
- Buses have driver details, up to ten ordered drop points, and multiple faculty in charge. One faculty member has one current bus assignment.
- Student statuses: Active, Inactive, Not available. Only active bus assignments with a valid drop point can be marked.
- Attendance is unique per student/date, with latest faculty editor recorded.
- Admin PDF/CSV reports use the selected date and search across all matching records.
- Automatic search and filters, responsive design, faculty name/ID display.

## Acceptance
All migrated row counts and full-row hashes match the source snapshot. Existing login, synchronization, transport editing, faculty access, attendance and reports operate through TiDB. ERP data remains read-only in normal application use. Secrets are excluded from these documents.
