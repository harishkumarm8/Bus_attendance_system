<?php
require __DIR__.'/config.php';
$action=is_string($_GET['action']??null)?$_GET['action']:'';
if($action==='logout') { check_post(); $_SESSION=[]; $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);session_destroy();go('login.php'); }
if(isset($_SESSION['role'])) go($_SESSION['role']==='admin'?'admin.php':'staff.php');
$error='';$needsSetup=false;
try {
    $local=db();$needsSetup=!(bool)query($local,'SELECT id FROM admins LIMIT 1')->fetch();
    if($_SERVER['REQUEST_METHOD']==='POST') {
        check_post();$username=input('username',100);$password=$_POST['password']??'';
        if(!is_string($password)||strlen($password)>1024) throw new RuntimeException('Enter a valid password.');
        $key=hash('sha256',($_SERVER['REMOTE_ADDR']??'local'));
        // Atomic per-address limit shared across sessions and usernames.
        record_login_attempt($local,$key);
        if((int)query($local,'SELECT attempts FROM login_attempts WHERE attempt_key=?',[$key])->fetchColumn()>10) throw new RuntimeException('Too many login attempts. Wait 15 minutes before trying again.');
        if($action==='setup') {
            if(!$needsSetup || strlen(SETUP_TOKEN)<32 || !hash_equals(SETUP_TOKEN,input('setup_token',256))) throw new RuntimeException('Admin setup is unavailable or the setup token is incorrect.');
            if(strlen($password)<12 || strlen($password)>72) throw new RuntimeException('Use an admin password between 12 and 72 bytes.');
            store_put($local,'admins',['id'=>1,'username'=>$username,'password_hash'=>password_hash($password,PASSWORD_DEFAULT)]);
            store_delete($local,'login_attempts','attempt_key',$key);flash('Admin created. Clear SETUP_TOKEN in config.php, then sign in.');go('login.php');
        }
        // Resolve the role server-side. A local admin username takes precedence.
        $admin=query($local,'SELECT * FROM admins WHERE username=?',[$username])->fetch();
        $role=$admin?'admin':'staff';$id=null;
        if($admin) {
            if($password!=='' && password_verify($password,$admin['password_hash'])) $id=$admin['id'];
        } else {
            // Faculty ID-only access, explicitly requested for the local installation.
            $ERP=db(true);
            require_columns($ERP,'erp_faculty',['f_id','f_fname','f_lname']);
            $rows=query($ERP,"SELECT f_id,f_fname,f_lname FROM erp_faculty WHERE f_id=? LIMIT 2",[$username])->fetchAll();
            if(count($rows)===1 && faculty_enabled((string)$rows[0]['f_id'])) $id=$rows[0]['f_id'];
        }
        if($id===null) throw new RuntimeException('Login failed. Check your admin credentials or faculty ID.');
        store_delete($local,'login_attempts','attempt_key',$key);
        session_regenerate_id(true);$_SESSION=['role'=>$role,'user_id'=>(string)$id,'username'=>$username,'last_activity'=>time(),'csrf'=>bin2hex(random_bytes(32))];go($role.'.php');
    }
} catch(Throwable $ex) { $error=safe_error($ex); }
page_start($action==='setup'?'Set up your workspace':'Bus attendance management');
?>
<section class="login-grid"><div class="login-story"><span class="brand-icon"><i class="bi bi-bus-front" aria-hidden="true"></i></span><h2 class="heading-3">A clear view of<br>every campus journey.</h2><p>Manage student transport and keep daily attendance in one place.</p><div class="story-item"><i class="bi bi-people" aria-hidden="true"></i><div><strong>One student directory</strong><p>Synchronize transport students from your campus records.</p></div></div><div class="story-item"><i class="bi bi-calendar-check" aria-hidden="true"></i><div><strong>Attendance by date</strong><p>Record, review and update each day with confidence.</p></div></div></div>
<article class="panel login-panel"><div class="eyebrow">WELCOME TO GRACE COLLEGE</div><h2 class="heading-5"><?=$action==='setup'?'Create the first admin':'Sign in to your workspace'?></h2><p class="caption-2">Staff: enter your faculty ID and leave the password blank. Admin: enter your username and password.</p>
<?php if($error):?><div class="alert alert-danger" role="alert"><?=e($error)?></div><?php endif;?>
<form method="post" action="login.php<?=$action==='setup'?'?action=setup':''?>"><?php csrf_field(); ?>
<label class="form-label" for="username">Username or faculty ID</label><input class="form-control" id="username" name="username" maxlength="100" autocomplete="username" value="<?=e(is_string($_POST['username']??null)?$_POST['username']:'')?>" required>
<label class="form-label" for="password"><?=$action==='setup'?'Password':'Admin password (staff leave blank)'?></label><input class="form-control" id="password" name="password" type="password" autocomplete="<?=$action==='setup'?'new-password':'current-password'?>" <?=$action==='setup'?'required minlength="12" maxlength="72"':''?>>
<?php if($action==='setup'):?><label class="form-label" for="setup_token">Setup token</label><input class="form-control" id="setup_token" name="setup_token" type="password" autocomplete="off" required><p class="caption-2">Set a random secret of at least 32 characters in config.php first.</p><?php endif;?>
<button class="btn btn-primary w-100 login-submit" type="submit"><?=$action==='setup'?'Create admin account':'Sign in'?> <i class="bi bi-arrow-right" aria-hidden="true"></i></button></form>
<?php if($needsSetup && $action!=='setup'):?><a class="setup-link" href="login.php?action=setup">Set up the first admin account</a><?php elseif($action==='setup'):?><a class="setup-link" href="login.php">Back to sign in</a><?php endif;?>
</article></section><?php page_end(); ?>
