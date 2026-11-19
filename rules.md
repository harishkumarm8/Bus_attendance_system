# Project rules

1. Preserve the four-PHP-file limit. Use action routes for additional pages and APIs.
2. TiDB is the target authoritative database. Never silently fall back to localhost databases.
3. Treat erp_student, erp_faculty and erp_class as read-only references. Do not edit ERP records from admin forms.
4. Store all credentials only in protected configuration; never in documentation, logs, HTML, JavaScript or committed migration reports.
5. Require certificate-verified TLS for remote MySQL. Never disable certificate validation to fix connectivity.
6. Keep admin password verification, staff access checks, CSRF, output escaping and prepared statements.
7. Faculty ID-only login is an explicit user requirement. Admin disabling must invalidate access on the next request; do not add passwords without a request.
8. Preserve unique student/date attendance and bus/drop validation. Do not overwrite transport assignments during ERP synchronization.
9. Do not guess class-year or department values. Preserve the verified college mappings.
10. No business data in browser storage or local database fallback. Keep source copies until migration validation is complete; deletion requires a separate instruction.
11. Protect existing functionality during design work. Retain college logo, plum/gold theme and accessible controls.
12. Read prd.md, archecture.md, rules.md, design.md, task.md and memory.md before future changes. Update task/memory after substantive work.
13. Verify new migrations against TiDB compatibility. Never rerun destructive schema imports against populated production databases.
