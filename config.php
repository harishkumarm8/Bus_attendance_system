<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli' && is_file(__DIR__.'/database/migration.lock')) { http_response_code(503); header('Retry-After: 120'); exit('Database migration in progress. Please refresh in a few minutes.'); }

// Credentials are loaded from environment variables (set in Vercel dashboard).
// Never hardcode secrets here — this file is committed to the repository.
const TIDB_DB = [
    'host'    => (getenv('TIDB_HOST')    ?: 'gateway01.ap-northeast-1.prod.aws.tidbcloud.com'),
    'port'    => (int)(getenv('TIDB_PORT') ?: 4000),
    'user'    => (getenv('TIDB_USER')    ?: ''),
    'password'=> (getenv('TIDB_PASSWORD')?: ''),
    'ssl_ca'  => __DIR__.'/certs/isrgrootx1.pem',
];
const APP_DB = TIDB_DB + ['name' => (getenv('TIDB_DB_NAME') ?: 'erp')];
const ERP_DB = TIDB_DB + ['name' => (getenv('TIDB_DB_NAME') ?: 'erp')];

// Set a random 32+ character secret temporarily, create the admin, then clear it.
const SETUP_TOKEN = (getenv('SETUP_TOKEN') ?: '');
// Fill these ONLY after inspecting the actual schema and existing authentication code.
const FACULTY_USERNAME_COLUMN = 'f_id';
const FACULTY_PASSWORD_COLUMN = '';
// Supported explicit formats: password_hash, md5, sha256, plaintext. Never auto-detect legacy formats.
const FACULTY_PASSWORD_FORMAT = '';
// Optional verified class mapping. All three must be set together; otherwise year is NULL.
const CLASS_TABLE = 'erp_class';
const CLASS_ID_COLUMN = 'cls_id';
const CLASS_YEAR_COLUMN = 'cls_sem';
// The imported class field contains semesters, not class IDs or calendar years.
const CLASS_YEAR_IS_SEMESTER = true;

date_default_timezone_set('Asia/Kolkata');
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
session_name('bus_attendance_session');
session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off','samesite'=>'Lax','path'=>'/']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; script-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");

function e($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function go(string $url): void { header('Location: '.$url, true, 303); exit; }
function db(bool $ERP = false): PDO {
    static $connections = [];
    $key = $ERP ? 'ERP' : 'app';
    if (isset($connections[$key])) return $connections[$key];
    $c = $ERP ? ERP_DB : APP_DB;
    $remote = !in_array($c['host'], ['127.0.0.1','localhost','::1'], true);
    if ($ERP && $c['user'] === '') throw new RuntimeException('ERP credentials are not configured. Update config.php.');
    $options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_TIMEOUT=>5];
    if ($remote) {
        if ($c['ssl_ca'] === '' || !is_file($c['ssl_ca'])) throw new RuntimeException('Configure a valid ERP TLS CA certificate in config.php. Remote MySQL access and verified TLS are required.');
        $options[PDO::MYSQL_ATTR_SSL_CA] = $c['ssl_ca'];
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    try {
        $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",$c['user'],$c['password'],$options);
        if ($remote) {
            $cipher = $pdo->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetch();
            if (empty($cipher['Value'])) throw new RuntimeException('ERP connection did not negotiate TLS.');
        }
        if($ERP) $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        else $GLOBALS['app_pdo']=$pdo;
        $pdo->exec("SET time_zone = '+05:30'");
        return $connections[$key] = $pdo;
    } catch (PDOException $ex) {
        throw new RuntimeException($ERP ? 'TiDB reference connection unavailable. Check connectivity and ERP_DB configuration.' : 'TiDB application database unavailable. Check connectivity and APP_DB configuration. No local fallback is used.');
    }
}
// Department codes supplied by the college; digits 7-9 of a 12-digit register number.
function student_department(string $register, ?string $year): string {
    $codes=['243'=>'AI & DS','104'=>'CSE','105'=>'EEE','106'=>'ECE','114'=>'MECH','103'=>'CIVIL',
            '631'=>'MBA','405'=>'M.E. CSE','401'=>'M.E. AE','411'=>'M.E. PSE'];
    $register=trim($register);
    if(!preg_match('/^[0-9]{12}$/D',$register)) return '';
    $code=substr($register,6,3);
    if(!isset($codes[$code])) return '';
    if($year==='1' && in_array($code,['243','104','105','106','114','103'],true)) return 'H&S';
    return $codes[$code];
}
function academic_year($value): ?string {
    if ($value === null) return null;
    $value = trim((string)$value);
    if (!CLASS_YEAR_IS_SEMESTER) return $value !== '' ? $value : null;
    if (!preg_match('/^([1-8])(?:[A-Za-z])?$/D', $value, $match)) return null;
    return (string)(int)ceil((int)$match[1] / 2);
}
// Four-table cloud model: all mutable app entities are typed JSON records in Attence.
const APP_FIELDS = [
 'students'=>['student_id','reg_no','student_name','year','dept','bus_no','drop_point','drop_point_status','bus_status'],
 'attendance'=>['id','student_id','attendance_date','attendance_status','staff_user_id'],
 'admins'=>['id','username','password_hash'], 'buses'=>['bus_no','driver_name','driver_phone'],
 'faculty_access'=>['faculty_id','access_enabled','bus_no'],
 'bus_drop_points'=>['bus_no','position','drop_point'],
 'login_attempts'=>['attempt_key','attempts','window_start']
];
function record_key(string $type,array $row): string {
    $fields=['students'=>['student_id'],'attendance'=>['student_id','attendance_date'],'admins'=>['id'],'buses'=>['bus_no'],'faculty_access'=>['faculty_id'],'bus_drop_points'=>['bus_no','position'],'login_attempts'=>['attempt_key']];
    if(!isset($fields[$type]))throw new RuntimeException('Unknown application record type.');
    return hash('sha256',json_encode(array_map(fn($f)=>(string)$row[$f],$fields[$type]),JSON_THROW_ON_ERROR));
}
function raw_query(PDO $pdo,string $sql,array $params=[]): PDOStatement { $st=$pdo->prepare($sql);$st->execute($params);return $st; }
function json_field(string $field): string {
    if(!preg_match('/^[a-z_]+$/D',$field))throw new RuntimeException('Invalid record field.');
    return "CASE WHEN JSON_TYPE(JSON_EXTRACT(payload,'$.".$field."'))='NULL' THEN NULL ELSE JSON_UNQUOTE(JSON_EXTRACT(payload,'$.".$field."')) END";
}
function store_put(PDO $pdo,string $type,array $row,?array $update=null): PDOStatement {
    $sql='INSERT INTO Attence (record_type,record_key,payload) VALUES (?,?,?)';
    $params=[$type,record_key($type,$row),json_encode($row,JSON_THROW_ON_ERROR)];
    if($update!==null) {
        $parts=[];foreach($update as $field){$parts[]="'$.".$field."',CAST(? AS JSON)";$params[]=json_encode($row[$field],JSON_THROW_ON_ERROR);}
        $sql.=' ON DUPLICATE KEY UPDATE payload=JSON_SET(payload,'.implode(',',$parts).')';
    }
    return raw_query($pdo,$sql,$params);
}
function store_update(PDO $pdo,string $type,array $key,array $patch): PDOStatement {
    $parts=[];$params=[];foreach($patch as $field=>$value){$parts[]="'$.".$field."',CAST(? AS JSON)";$params[]=json_encode($value,JSON_THROW_ON_ERROR);}
    $params[]=$type;$params[]=record_key($type,$key);
    return raw_query($pdo,'UPDATE Attence SET payload=JSON_SET(payload,'.implode(',',$parts).') WHERE record_type=? AND record_key=?',$params);
}
function store_delete(PDO $pdo,string $type,string $field,$value): PDOStatement {
    return raw_query($pdo,'DELETE FROM Attence WHERE record_type=? AND ('.json_field($field).')=?',[$type,$value]);
}
function record_login_attempt(PDO $pdo,string $key): void {
    $window=json_field('window_start');$attempts=json_field('attempts');
    $expired="(($window)<DATE_FORMAT(NOW()-INTERVAL 15 MINUTE,'%Y-%m-%d %H:%i:%s'))";
    raw_query($pdo,"INSERT INTO Attence (record_type,record_key,payload) VALUES ('login_attempts',?,JSON_OBJECT('attempt_key',?,'attempts',1,'window_start',DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s'))) ON DUPLICATE KEY UPDATE payload=JSON_SET(payload,'$.attempts',IF($expired,1,CAST(($attempts) AS UNSIGNED)+1),'$.window_start',IF($expired,DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s'),($window)))",[record_key('login_attempts',['attempt_key'=>$key]),$key]);
}
function query(PDO $pdo, string $sql, array $params=[]): PDOStatement {
    if($pdo===($GLOBALS['app_pdo']??null)) {
        if(!preg_match('/^SELECT\b/i',trim($sql)))throw new RuntimeException('Use typed storage helpers for application writes.');
        if(stripos($sql,'FOR UPDATE')!==false && preg_match('/FROM students WHERE student_id=\?/i',$sql)) {
            raw_query($pdo,"SELECT record_id FROM Attence WHERE record_type='students' AND record_key=? FOR UPDATE",[record_key('students',['student_id'=>$params[0]])]);
            $sql=str_ireplace('FOR UPDATE','',$sql);
        }
        $tables=implode('|',array_keys(APP_FIELDS));
        $sql=preg_replace_callback('/\b(FROM|JOIN)\s+('.$tables.')\b(?:\s+(?:AS\s+)?(?!WHERE\b|LEFT\b|RIGHT\b|INNER\b|JOIN\b|ORDER\b|GROUP\b|LIMIT\b|FOR\b|ON\b|HAVING\b|UNION\b)([a-zA-Z_]\w*))?/i',function($m){
            $type=strtolower($m[2]);$columns=[];
            foreach(APP_FIELDS[$type] as $field){$expr=json_field($field);if($field==='id'&&$type==='attendance')$expr="COALESCE(($expr),record_id)";if(in_array($field,['id','position','attempts','access_enabled'],true))$expr="CAST(($expr) AS SIGNED)";$columns[]=$expr.' AS `'.$field.'`';}
            return $m[1].' (SELECT '.implode(',',$columns)." FROM Attence WHERE record_type='".$type."') AS ".($m[3]??$type);
        },$sql);
    }
    return raw_query($pdo,$sql,$params);
}
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): void { echo '<input type="hidden" name="csrf" value="'.e(csrf()).'">'; }
function check_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit('Use the form to perform this action.'); }
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals(csrf(), $_POST['csrf'])) { http_response_code(403); exit('Form expired. Reload the page and try again.'); }
}
function input(string $key, int $max=150, bool $required=true): string {
    $v=$_POST[$key] ?? ''; if (!is_string($v)) throw new RuntimeException('Invalid input.');
    $v=trim($v); if (($required && $v==='') || strlen($v)>$max) throw new RuntimeException('Check the length of '.$key.'.'); return $v;
}
function date_value($v): string {
    if (!is_string($v)) throw new RuntimeException('Invalid attendance date.');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
    if (!$d || $d->format('Y-m-d') !== $v || $v < '2000-01-01' || $v > date('Y-m-d')) throw new RuntimeException('Choose a valid date from 2000 through today.');
    return $v;
}
function ident(string $s): string { if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$s)) throw new RuntimeException('ERP schema mapping is missing or invalid in config.php.'); return '`'.$s.'`'; }
function columns(PDO $pdo, string $table): array { return array_column($pdo->query('SHOW COLUMNS FROM '.ident($table))->fetchAll(),'Field'); }
function require_columns(PDO $pdo,string $table,array $required): void { if (array_diff($required,columns($pdo,$table))) throw new RuntimeException('Required columns were not found in '.$table.'. Inspect the schema and update config.php.'); }
function verify_faculty_password(string $password,string $stored): bool {
    switch (FACULTY_PASSWORD_FORMAT) {
        case 'password_hash': return password_verify($password,$stored);
        case 'md5': return hash_equals(strtolower($stored),md5($password));
        case 'sha256': return hash_equals(strtolower($stored),hash('sha256',$password));
        case 'plaintext': return hash_equals($stored,$password);
        default: throw new RuntimeException('Confirm the existing faculty password format and configure it in config.php.');
    }
}
function faculty_enabled(string $id): bool {
    $row=query(db(),'SELECT access_enabled FROM faculty_access WHERE faculty_id=?',[$id])->fetch();
    return !$row || (int)$row['access_enabled']===1;
}
function bus_options(PDO $pdo): array { return query($pdo,'SELECT bus_no FROM buses ORDER BY bus_no')->fetchAll(PDO::FETCH_COLUMN); }
function validate_bus(PDO $pdo,string $bus): void {
    if($bus!=='' && !query($pdo,'SELECT bus_no FROM buses WHERE bus_no=?',[$bus])->fetch()) throw new RuntimeException('Choose a bus from the list. Add it to the bus list first if needed.');
}
function require_role(string $role): void {
    if (empty($_SESSION['role'])) go('login.php');
    if ($_SESSION['role'] !== $role) { http_response_code(403); exit('You do not have permission to access this dashboard.'); }
    if (time()-($_SESSION['last_activity'] ?? 0)>1800) { $_SESSION=[]; go('login.php'); }
    $_SESSION['last_activity']=time();
    if ($role==='staff') {
        try { $row=query(db(true),"SELECT f_id,f_fname,f_lname FROM erp_faculty WHERE f_id=?",[$_SESSION['user_id']])->fetch(); }
        catch (Throwable $ex) { http_response_code(503); page_start('ERP verification unavailable'); echo '<div class="alert alert-warning">'.e(safe_error($ex)).'</div><a href="staff.php">Retry verification</a>'; page_end(); exit; }
        if (!$row || !faculty_enabled((string)$_SESSION['user_id'])) { $_SESSION=[]; go('login.php'); }
        $_SESSION['faculty_name']=trim($row['f_fname'].' '.$row['f_lname']);
    }
}
function safe_error(Throwable $ex): string { return $ex instanceof RuntimeException && !$ex instanceof PDOException ? $ex->getMessage() : 'The operation could not be completed. Check database configuration and field lengths, then retry.'; }
function flash(string $text,string $type='success'): void { $_SESSION['flash']=[$text,$type]; }
function badge(?string $value): string { $value=$value ?: 'Not yet marked'; $cls=in_array($value,['Active','Present'],true)?'good':(in_array($value,['Inactive','Absent'],true)?'muted':'pending'); return '<span class="status '.$cls.'">'.e($value).'</span>'; }
// Reuse the exact supplied header artwork through SVG viewports, without redrawing the logos.
function college_logo(bool $system=false): string {
    $view=$system?'20 8 270 82':'688 4 532 88';
    $label=$system?'GracER Campus Management System':'Grace College of Engineering';
    $class=$system?'system-logo':'college-logo';
    return '<svg class="'.$class.'" viewBox="'.$view.'" role="img" aria-label="'.$label.'" xmlns="http://www.w3.org/2000/svg"><image href="assets/college-reference.png" width="1912" height="875" /></svg>';
}
function page_start(string $title, ?string $role=null, string $active=''): void {
?> <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="description" content="Campus bus attendance and transport management"><title><?=e($title)?> · Grace College of Engineering</title><link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%23550861'/%3E%3Ctext x='12' y='44' font-size='40' fill='white'%3EB%3C/text%3E%3C/svg%3E"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"><link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet"><link href="css/style.css?v=grace-3" rel="stylesheet"></head><body><a class="skip-link" href="#main">Skip to content</a>
<header class="college-header"><a class="college-brand" href="<?=$role?e($role).'.php':'login.php'?>"><? =college_logo()?></a><div class="header-account"><?php if($role):?><span class="header-welcome">Welcome, <?=e($role==='staff'?(($_SESSION['faculty_name']??'Faculty').' ('.$_SESSION['user_id'].')'):$_SESSION['username'])?></span><span class="caption-2"><?=e($role==='admin'?'Administrator':'Faculty / Staff')?></span><?php else:?><span class="header-welcome">Bus attendance</span><span class="caption-2">Campus transport management</span><?php endif;?></div></header>
<?php if($role): ?><aside class="sidebar" id="sidebar"><a class="brand" href="<?=$role?>.php"><span class="brand-icon"><i class="bi bi-bus-front" aria-hidden="true"></i></span>Bus attendance</a><div class="nav-label">GRACE COLLEGE OF ENGINEERING</div><nav aria-label="Main navigation"><?php
    $items=$role==='admin'?['dashboard'=>['Dashboard','grid'],'students'=>['Student management','people'],'faculty'=>['Faculty management','person-gear'],'add_bus'=>['Add bus','bus-front'],'sync_students'=>['Synchronize students','arrow-repeat'],'reports'=>['Attendance reports','bar-chart']]:['dashboard'=>['Dashboard','grid'],'attendance'=>['Bus attendance','check2-square'],'history'=>['Attendance history','clock-history']];
    foreach($items as $key=>$item) echo '<a '.($active===$key?'aria-current="page"':'').' class="nav-item '.($active===$key?'selected':'').'" href="'.$role.'.php?action='.$key.'"><i class="bi bi-'.$item[1].'" aria-hidden="true"></i>'.e($item[0]).'</a>';
?><form class="logout" method="post" action="login.php?action=logout"><?php csrf_field();?><button class="nav-item" type="submit"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>Log out</button></form></nav><div class="sidebar-footer"><span class="status good">TiDB Cloud</span><p class="caption-2">Data stored on TiDB Cloud (Asia-Pacific).</p></div></aside><?php endif;?>
<div class="<?= $role?'workspace':'login-workspace' ?>"><? php if($role):?><header class="topbar navigation-only"><button class="menu-button" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="Toggle navigation"><i class="bi bi-list"></i></button></header><?php endif;?><main id="main"><div class="page-heading"><div><div class="eyebrow">GRACE COLLEGE OF ENGINEERING</div><h1 class="heading-4"><?=e($title)?></h1></div><span class="caption-2"><?=e(date('D, d M Y'))?></span></div><?php
    if(isset($_SESSION['flash'])) { [$msg,$type]=$_SESSION['flash'];unset($_SESSION['flash']);echo '<div role="status" class="alert alert-'.e($type).'">'.e($msg).'</div>'; }
}
function page_end(): void { echo '</main><footer class="page-footer caption-2">Grace College of Engineering · Bus attendance management</footer></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script><script src="js/vendor/jspdf.umd.min.js" defer></script><script src="js/vendor/jspdf.plugin.autotable.min.js" defer></script><script src="js/report-pdf.js" defer></script><script src="js/script.js?v=student-route-3" defer></script></body></html>'; }
function stats(array $items): void { echo '<section class="stats" aria-label="Summary">';foreach($items as $label=>$value)echo '<article class="stat"><span class="caption-2">'.e($label).'</span><strong class="heading-3">'.e($value).'</strong></article>';echo '</section>'; }
function pager(int $total,int $page,array $params): void { $pages=max(1,(int)ceil($total/25)); echo '<nav class="pagination-bar" aria-label="Pagination"><span class="caption-2">'.e($total).' records · Page '.$page.' of '.$pages.'</span><div>';foreach(['Previous'=>$page-1,'Next'=>$page+1] as $label=>$p) if($p>=1 && $p<=$pages) echo '<a class="btn btn-outline-secondary btn-sm" href="?'.e(http_build_query(array_merge($params,['page'=>$p]))).'">'.$label.'</a> ';echo '</div></nav>'; }
