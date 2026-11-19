# Current task — TiDB migration

## Completed
- [x] Inspect local table inventory.
- [x] Verify TiDB credentials and certificate-validated TLS.
- [x] Create requested project documents (.gitignore, archecture.md, design.md, memory.md, prd.md, rules.md, task.md).
- [x] Confirm scope: four cloud tables — erp_class, erp_student, erp_faculty (read-only), Attence (all app writes).
- [x] Design four-table JSON-store layer in config.php (store_put, store_update, store_delete, query() rewriter).
- [x] Implement full application against Attence store (admin.php, staff.php, login.php, config.php all updated).
- [x] ERP connection set SESSION TRANSACTION READ ONLY server-side.
- [x] APP_DB and ERP_DB both point to TiDB erp database (single TiDB connection target).
- [x] Migration report with row counts and full-row SHA-256 checksums recorded (tidb_migration_report.json).
  - 1,247 ERP students · 75 faculty · 48 classes
  - 299 app students · 2 attendance · 1 admin · 1 bus · 2 faculty_access · 3 drop points

## Remaining
- [ ] Run database/attence_schema.sql on TiDB to create the Attence table (once only, before any app traffic).
- [ ] Confirm Attence table exists: SHOW TABLES in erp database on TiDB.
- [ ] Verify login via browser: admin login, faculty ID login, rate-limit and logout.
- [ ] Verify sync_students pulls from erp_student and writes to Attence correctly.
- [ ] Verify bus/drop-point/faculty controls through admin.php.
- [ ] Verify staff attendance marking and history through staff.php.
- [ ] Verify PDF and CSV exports.
- [ ] Run tests/verify.py smoke tests (requires XAMPP PHP; test database is local-only isolation).
- [ ] Update sidebar footer text to "TiDB Cloud" after confirming live connectivity.
- [ ] Update final migration evidence in this file and memory.md.
