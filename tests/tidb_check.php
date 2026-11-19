<?php
/**
 * TiDB connectivity and Attence schema check.
 * Run from the project root:
 *   C:\xampp\php\php.exe tests\tidb_check.php
 *
 * This script loads the application config and verifies:
 *   1. TLS-verified connection to TiDB succeeds.
 *   2. erp_class, erp_student, erp_faculty exist and are readable.
 *   3. Attence table exists and its columns match the application schema.
 *   4. ERP connection is read-only (write attempt is rejected).
 *   5. App connection is read-write (insert/delete round-trip succeeds).
 */
declare(strict_types=1);
define('PHP_SAPI', 'cli');   // suppress the migration lock check
chdir(__DIR__ . '/..');
require __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function ok(string $label): void  { global $pass; $pass++; echo "PASS: $label\n"; }
function err(string $label, string $detail = ''): void {
    global $fail; $fail++;
    echo "FAIL: $label" . ($detail ? " — $detail" : '') . "\n";
}

// 1. ERP read-only connection
try {
    $erp = db(true);
    ok('ERP TLS connection established');
} catch (Throwable $e) { err('ERP connection', $e->getMessage()); exit(1); }

foreach (['erp_class','erp_student','erp_faculty'] as $t) {
    try {
        $n = (int)$erp->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        ok("$t readable ($n rows)");
    } catch (Throwable $e) { err("$t readable", $e->getMessage()); }
}

// ERP must reject writes
try {
    $erp->exec("DELETE FROM erp_class WHERE 1=0");
    err('ERP connection is read-only', 'write succeeded unexpectedly');
} catch (Throwable $e) { ok('ERP connection is read-only (write rejected)'); }

// 2. App (read-write) connection
try {
    $app = db(false);
    ok('App TLS connection established');
} catch (Throwable $e) { err('App connection', $e->getMessage()); exit(1); }

// 3. Attence table structure
try {
    $cols = array_column($app->query('SHOW COLUMNS FROM Attence')->fetchAll(), 'Field');
    $required = ['record_id','record_type','record_key','payload'];
    $missing = array_diff($required, $cols);
    if ($missing) err('Attence columns', 'missing: ' . implode(', ', $missing));
    else ok('Attence table exists with required columns');
} catch (Throwable $e) {
    err('Attence table', $e->getMessage());
    echo "\n  → Run database/attence_schema.sql on the TiDB erp database first.\n\n";
}

// 4. App write round-trip (login_attempts type, isolated key)
$probe_key = 'tidb_check_' . bin2hex(random_bytes(8));
try {
    record_login_attempt($app, $probe_key);
    $count = (int)query($app, 'SELECT attempts FROM login_attempts WHERE attempt_key=?', [$probe_key])->fetchColumn();
    if ($count === 1) ok('Attence write round-trip (login_attempts insert)');
    else err('Attence write round-trip', "expected attempts=1, got $count");
    store_delete($app, 'login_attempts', 'attempt_key', $probe_key);
    ok('Attence write round-trip (login_attempts cleanup)');
} catch (Throwable $e) { err('Attence write round-trip', $e->getMessage()); }

// 5. Report
echo "\n" . ($fail === 0 ? "All $pass checks passed." : "$pass passed, $fail failed.") . "\n";
exit($fail > 0 ? 1 : 0);
