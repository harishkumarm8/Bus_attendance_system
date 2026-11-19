<?php
require __DIR__.'/config.php';require_role('admin');
$action=is_string($_GET['action']??null)?$_GET['action']:'dashboard';
if(!in_array($action,['dashboard','students','sync_students','inspect','update_student','reports','export','pdf_data','faculty','update_faculty','add_bus'],true)) { http_response_code(404);page_start('Page not found','admin');echo '<a href="admin.php">Back to dashboard</a>';page_end();exit; }
$error='';$schema=[];
try {
    $local=db();
    if($action==='sync_students' && $_SERVER['REQUEST_METHOD']==='POST') {
        check_post();$ERP=db(true);
        require_columns($ERP,'erp_student',['stu_id','stu_regno','stu_fname','stu_lname','stu_dept','cls_id','stu_transport']);
        $year='NULL';$join='';
        if(CLASS_TABLE!=='' || CLASS_ID_COLUMN!=='' || CLASS_YEAR_COLUMN!=='') {
            require_columns($ERP,CLASS_TABLE,[CLASS_ID_COLUMN,CLASS_YEAR_COLUMN]);
            $ct=ident(CLASS_TABLE);$ci=ident(CLASS_ID_COLUMN);$cy=ident(CLASS_YEAR_COLUMN);
            if($ERP->query("SELECT $ci FROM $ct GROUP BY $ci HAVING COUNT(*)>1 LIMIT 1")->fetch()) throw new RuntimeException('The configured class identifier is not unique. Check the class mapping.');
            $year='c.'.$cy;$join=" LEFT JOIN $ct c ON c.$ci=s.cls_id";
        }
        $source=query($ERP,"SELECT s.stu_id,s.stu_regno,s.stu_fname,s.stu_lname,s.stu_dept,$year AS academic_year FROM erp_student s $join WHERE s.stu_transport=?",['yes']);
        $local->beginTransaction();$count=0;
        try {
            while($s=$source->fetch()) {
                if(trim((string)$s['stu_id'])==='') throw new RuntimeException('A ERP student has an empty ID. Synchronization was rolled back.');
                store_put($local,'students',['student_id'=>(string)$s['stu_id'],'reg_no'=>(string)$s['stu_regno'],'student_name'=>trim($s['stu_fname'].' '.$s['stu_lname']),'year'=>academic_year($s['academic_year']),'dept'=>student_department((string)$s['stu_regno'],academic_year($s['academic_year'])),'bus_no'=>null,'drop_point'=>null,'drop_point_status'=>'Inactive','bus_status'=>'Inactive'],['reg_no','student_name','year','dept']);$count++;
            }
            $local->commit();
        } catch(Throwable $ex) { $local->rollBack();throw $ex; }
        $_SESSION['sync_count']=$count;flash("Synchronized $count eligible students. Existing transport assignments were preserved.");go('admin.php?action=students');
    }
    if($action==='inspect') {
        check_post();$ERP=db(true);
        foreach($ERP->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) $schema[$table]=columns($ERP,$table);
    }
    if($action==='add_bus' && $_SERVER['REQUEST_METHOD']==='POST') {
        check_post();$bus=input('bus_no',50);$driver=input('driver_name',150);$phone=input('driver_phone',25);$original=input('original_bus',50,false);
        if(!preg_match('/^[+0-9 ()-]{7,25}$/D',$phone) || strlen(preg_replace('/[^0-9]/','',$phone))<7) throw new RuntimeException('Enter a valid driver phone number.');
        $points=$_POST['drop_points']??[];
        if(!is_array($points)||count($points)>10) throw new RuntimeException('Enter up to 10 drop points.');
        $clean=[];foreach($points as $point) { if(!is_string($point)||strlen(trim($point))>150) throw new RuntimeException('Invalid drop point name.');if(trim($point)!=='')$clean[]=trim($point); }
        if(!$clean) throw new RuntimeException('Enter at least one drop point.');
        if(count(array_unique(array_map('strtolower',$clean)))!==count($clean)) throw new RuntimeException('Drop point names must be unique for this bus.');
        if($original!=='' && $original!==$bus) throw new RuntimeException('Existing bus numbers cannot be renamed here.');
        $staffIds=$_POST['staff_ids']??[];
        if(!is_array($staffIds)||count($staffIds)>200) throw new RuntimeException('Invalid staff selection.');
        $selected=[];
        foreach($staffIds as $staffId) {
            if(!is_string($staffId)||strlen($staffId)>64) throw new RuntimeException('Invalid faculty ID.');
            if($staffId==='')continue;
            if(in_array($staffId,$selected,true))throw new RuntimeException('Choose each staff member only once.');
            if(!query(db(true),'SELECT f_id FROM erp_faculty WHERE f_id=?',[$staffId])->fetch())throw new RuntimeException('Selected faculty no longer exists.');
            $selected[]=$staffId;
        }
        $local->beginTransaction();
        try {
            if($original==='') store_put($local,'buses',['bus_no'=>$bus,'driver_name'=>$driver,'driver_phone'=>$phone]);
            else { validate_bus($local,$original);store_update($local,'buses',['bus_no'=>$bus],['driver_name'=>$driver,'driver_phone'=>$phone]); }
            store_delete($local,'bus_drop_points','bus_no',$bus);
            foreach($clean as $i=>$point)store_put($local,'bus_drop_points',['bus_no'=>$bus,'position'=>$i+1,'drop_point'=>$point]);
            foreach(query($local,'SELECT faculty_id FROM faculty_access WHERE bus_no=?',[$bus])->fetchAll(PDO::FETCH_COLUMN) as $fid)store_update($local,'faculty_access',['faculty_id'=>$fid],['bus_no'=>null]);
            foreach($selected as $staffId)store_put($local,'faculty_access',['faculty_id'=>$staffId,'access_enabled'=>1,'bus_no'=>$bus],['bus_no']);
            $local->commit();
        } catch(Throwable $ex) { $local->rollBack();throw $ex; }
        flash('Bus, driver, drop points and staff in charge saved.');go('admin.php?action=add_bus');
    }
    if($action==='update_faculty') {
        check_post();$id=input('faculty_id',64);$bus=input('bus_no',50,false);$enabled=input('access_enabled',1);
        if(!in_array($enabled,['0','1'],true)) throw new RuntimeException('Choose Enabled or Disabled.');
        if(!query(db(true),'SELECT f_id FROM erp_faculty WHERE f_id=?',[$id])->fetch()) throw new RuntimeException('Faculty not found in ERP.');
        validate_bus($local,$bus);
        store_put($local,'faculty_access',['faculty_id'=>$id,'access_enabled'=>(int)$enabled,'bus_no'=>$bus?:null],['access_enabled','bus_no']);
        flash('Faculty access and bus assignment saved.');go('admin.php?action=faculty');
    }
    if($action==='update_student') {
        check_post();$id=input('student_id',64);$bus=input('bus_no',50,false);$drop=input('drop_point',150,false);$bs=input('bus_status',20);$ds=$drop!==''?'Active':'Inactive'; // Legacy column follows the drop point name automatically.
        if(!in_array($bs,['Active','Inactive','Not available'],true)) throw new RuntimeException('Invalid transport status.');
        validate_bus($local,$bus);
        if($drop!=='' && ($bus==='' || !query($local,'SELECT position FROM bus_drop_points WHERE bus_no=? AND drop_point=?',[$bus,$drop])->fetch())) throw new RuntimeException('Choose a drop point from the selected bus route.');
        if($bs==='Active' && ($bus==='' || $drop==='')) throw new RuntimeException('Assign a bus and drop point before activating them.');
        if(!query($local,'SELECT student_id FROM students WHERE student_id=?',[$id])->fetch()) throw new RuntimeException('Student not found.');
        store_update($local,'students',['student_id'=>$id],['bus_no'=>$bus?:null,'drop_point'=>$drop?:null,'bus_status'=>$bs,'drop_point_status'=>$ds]);
        flash('Student transport details saved.');go('admin.php?action=students');
    }
    $date=date_value($_GET['date']??date('Y-m-d'));
    $search=is_string($_GET['q']??null)?substr(trim($_GET['q']),0,100):'';
    $where='(s.student_name LIKE ? OR s.reg_no LIKE ?)';$params=['%'.$search.'%','%'.$search.'%'];
    if($action==='pdf_data') {
        $rows=query($local,"SELECT s.student_id,s.student_name,s.reg_no,s.year,s.dept,s.bus_no,s.drop_point,a.attendance_status,a.staff_user_id FROM attendance a JOIN students s ON s.student_id=a.student_id WHERE a.attendance_date=? AND $where ORDER BY s.student_name,s.student_id",array_merge([$date],$params))->fetchAll();
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['date'=>$date,'search'=>$search,'rows'=>$rows],JSON_THROW_ON_ERROR);exit;
    }
    if($action==='export') {
        $rows=query($local,"SELECT a.attendance_date,s.student_id,s.student_name,s.reg_no,s.year,s.dept,s.bus_no,s.drop_point,a.attendance_status,a.staff_user_id FROM attendance a JOIN students s ON s.student_id=a.student_id WHERE a.attendance_date=? AND $where ORDER BY s.student_name",array_merge([$date],$params));
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="attendance-'.$date.'.csv"');
        $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Date','Student ID','Name','Register number','Year','Department','Bus','Drop point','Status','Faculty ID']);
        while($row=$rows->fetch(PDO::FETCH_NUM)) { foreach($row as &$cell) { $cell=(string)$cell;if(preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u',$cell))$cell="'".$cell; }unset($cell);fputcsv($out,$row); }fclose($out);exit;
    }
} catch(Throwable $ex) { if($action==='pdf_data'){http_response_code(400);header('Content-Type: application/json');echo json_encode(['error'=>safe_error($ex)]);exit;} $error=safe_error($ex); }
$titles=['faculty'=>'Faculty management','update_faculty'=>'Faculty management','add_bus'=>'Add bus','dashboard'=>'Transport overview','students'=>'Student management','sync_students'=>'Synchronize students','inspect'=>'ERP schema inspection','update_student'=>'Student management','reports'=>'Attendance reports','export'=>'Attendance reports'];
page_start($titles[$action],'admin',$action==='inspect'?'sync_students':$action);
if($error)echo '<div class="alert alert-danger" role="alert">'.e($error).'</div>';
try {
    $local=db();
    if($action==='add_bus') { render_bus_management($local); } elseif(in_array($action,['faculty','update_faculty'],true)) { render_faculty_management($local); } elseif($action==='sync_students'||$action==='inspect') { ?>
    <section class="panel"><h2 class="heading-5">Bring your student directory up to date</h2><p>Students are read from the imported local ERP database. Only students with transport set to yes are imported. Bus assignments and existing local students are preserved.</p><p class="caption-2">Student year is mapped from the ERP class semester. Department uses the college register code mapping, with H&amp;S for first year UG students. Passed out or unrecognized class values remain unassigned. Newly imported students start inactive.</p><form method="post" action="admin.php?action=sync_students"><?php csrf_field();?><button class="btn btn-primary" type="submit"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Synchronize students</button></form></section>
    <section class="panel"><h2 class="heading-5">Inspect ERP structure</h2><p>Read table and column names without exposing faculty credentials or changing ERP data. Verify password storage against your existing ERP authentication code.</p><form method="post" action="admin.php?action=inspect"><?php csrf_field();?><button class="btn btn-outline-secondary">Inspect schema</button></form><?php foreach($schema as $t=>$cols)echo '<h3 class="heading-6 schema-title">'.e($t).'</h3><p>'.e(implode(', ',$cols)).'</p>';?></section>
    <?php } elseif(in_array($action,['reports','export'],true)) {
        $date=$date??date('Y-m-d');$search=$search??'';
        $counts=query($local,"SELECT SUM(attendance_status='Present') AS present,SUM(attendance_status='Absent') AS absent FROM attendance WHERE attendance_date=?",[$date])->fetch();stats(['Present'=>$counts['present']??0,'Absent'=>$counts['absent']??0,'Report date'=>$date]);
        $params=['%'.$search.'%','%'.$search.'%'];$total=(int)query($local,"SELECT COUNT(*) FROM attendance a JOIN students s ON s.student_id=a.student_id WHERE a.attendance_date=? AND (s.student_name LIKE ? OR s.reg_no LIKE ?)",array_merge([$date],$params))->fetchColumn();$page=max(1,min((int)($_GET['page']??1),max(1,(int)ceil($total/25))));$offset=($page-1)*25;
        $rows=query($local,"SELECT s.*,a.attendance_status,a.staff_user_id FROM attendance a JOIN students s ON s.student_id=a.student_id WHERE a.attendance_date=? AND (s.student_name LIKE ? OR s.reg_no LIKE ?) ORDER BY s.student_name,s.student_id LIMIT 25 OFFSET $offset",array_merge([$date],$params))->fetchAll(); ?>
        <section class="panel"><div class="section-title"><h2 class="heading-5">Daily attendance report</h2><button type="button" id="download-report-pdf" class="btn btn-primary" data-url="?<?=e(http_build_query(['action'=>'pdf_data','date'=>$date,'q'=>$search]))?>"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Download PDF</button><a class="btn btn-outline-secondary" href="?<?=e(http_build_query(['action'=>'export','date'=>$date,'q'=>$search]))?>"><i class="bi bi-download"></i> Export CSV</a></div><p id="pdf-feedback" role="status" class="caption-2"></p><form class="toolbar auto-filter" method="get"><input type="hidden" name="action" value="reports"><label>Date<input class="form-control" type="date" name="date" value="<?=e($date)?>" min="2000-01-01" max="<?=date('Y-m-d')?>"></label><label class="search-field">Search students<input class="form-control" name="q" value="<?=e($search)?>" placeholder="Name or register number"></label></form><p class="caption-2">Counts include all saved records for this date. Transport details show current assignments.</p><div class="table-responsive"><table class="table"><thead><tr><th>Student</th><th>Register number</th><th>Bus / drop point</th><th>Status</th><th>Recorded by</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><strong><?=e($r['student_name'])?></strong><small><?=e($r['student_id'])?></small></td><td><?=e($r['reg_no'])?></td><td><?=e($r['bus_no']?:'Unassigned')?><small><?=e($r['drop_point']?:'Unassigned')?></small></td><td><?=badge($r['attendance_status'])?></td><td><?=e($r['staff_user_id'])?></td></tr><?php endforeach;?></tbody></table></div><?php if(!$rows)echo '<div class="empty-state"><i class="bi bi-calendar2-check"></i><h3 class="heading-6">No attendance records</h3><p>Choose another date or ask staff to mark attendance.</p></div>';pager($total,$page,['action'=>'reports','date'=>$date,'q'=>$search]);?></section>
    <?php } else {
        $buses=bus_options($local);$routePoints=[];foreach(query($local,'SELECT bus_no,drop_point FROM bus_drop_points ORDER BY bus_no,position')->fetchAll() as $stop)$routePoints[$stop['bus_no']][]=$stop['drop_point'];
        $summary=query($local,"SELECT COUNT(*) total,COUNT(DISTINCT CASE WHEN bus_status='Active' THEN NULLIF(bus_no,'') END) active_buses,COUNT(DISTINCT CASE WHEN bus_status='Inactive' THEN NULLIF(bus_no,'') END) inactive_buses,COUNT(DISTINCT CASE WHEN bus_status='Active' THEN NULLIF(TRIM(drop_point),'') END) active_drops FROM students")->fetch();
        stats(['Synchronized students'=>$summary['total'],'Active buses'=>$summary['active_buses'],'Inactive buses'=>$summary['inactive_buses'],'Active drop points'=>$summary['active_drops']]);
        $search=$search??'';
        $filters=[];$filterOptions=[];
        $conditions=['(s.student_name LIKE ? OR s.reg_no LIKE ?)'];
        $params=['%'.$search.'%','%'.$search.'%'];
        foreach(['year'=>'Year','dept'=>'Department','bus_no'=>'Bus'] as $field=>$label) {
            $value=$_GET[$field]??'';
            if(!is_string($value)||strlen($value)>150) throw new RuntimeException('Invalid student filter.');
            $filters[$field]=$value;
            // Column names come only from the fixed list above; values are bound.
            $filterOptions[$field]=query($local,"SELECT DISTINCT $field FROM students WHERE $field IS NOT NULL AND $field<>'' ORDER BY $field")->fetchAll(PDO::FETCH_COLUMN);
            if($value==='unassigned') $conditions[]="(s.$field IS NULL OR s.$field='')";
            elseif($value!=='') {
                if(strncmp($value,'value:',6)!==0) throw new RuntimeException('Invalid student filter.');
                $conditions[]="s.$field=?";$params[]=substr($value,6);
            }
        }
        $filtered=$search!=='' || count(array_filter($filters,fn($value)=>$value!==''))>0;
        $studentWhere=implode(' AND ',$conditions);
        $total=(int)query($local,"SELECT COUNT(*) FROM students s WHERE $studentWhere",$params)->fetchColumn();
        $page=max(1,min((int)($_GET['page']??1),max(1,(int)ceil($total/25))));$offset=($page-1)*25;
        $rows=query($local,"SELECT * FROM students s WHERE $studentWhere ORDER BY s.student_name,s.student_id LIMIT 25 OFFSET $offset",$params)->fetchAll(); ?>
        <section class="panel"><div class="section-title"><div><h2 class="heading-5">Student directory</h2><p class="caption-2">Manage bus assignments and drop points for your students.</p></div><a class="btn btn-primary" href="?action=sync_students"><i class="bi bi-arrow-repeat"></i> Synchronize students</a></div><form class="toolbar student-filters auto-filter" method="get"><input type="hidden" name="action" value="students"><label class="search-field">Search directory<input class="form-control" name="q" value="<?=e($search)?>" placeholder="Student name or register number"></label><?php foreach(['year'=>'Year','dept'=>'Department','bus_no'=>'Bus'] as $field=>$label):?><label><?=e($label)?><select class="form-select" name="<?=e($field)?>"><option value="">All <?=e(strtolower($label))?> values</option><option value="unassigned" <?=$filters[$field]==='unassigned'?'selected':''?>>Unassigned</option><?php foreach($filterOptions[$field] as $option):?><option value="<?=e('value:'.$option)?>" <?=$filters[$field]==='value:'.$option?'selected':''?>><?=e($option)?></option><?php endforeach;?></select></label><?php endforeach;?><a class="btn btn-outline-secondary" href="admin.php?action=students">Reset</a></form><p class="caption-2">Status applies to each student's assignment. A shared bus can have both active and inactive assignments. Retained students are included in the directory count.</p><div class="table-responsive"><table class="table"><thead><tr><th>Student ID</th><th>Student name</th><th>Register number</th><th>Year</th><th>Department</th><th>Bus</th><th>Drop point name</th><th>Bus status</th><th>Action</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['student_id'])?></td><td><strong><?=e($r['student_name'])?></strong></td><td><?=e($r['reg_no'])?></td><td><?=e($r['year']??'Unassigned')?></td><td><?=e($r['dept']?:'Unassigned')?></td><td><?=e($r['bus_no']?:'Unassigned')?></td><td><?=e($r['drop_point']?:'Unassigned')?></td><td><?=badge($r['bus_status'])?></td><td><button class="btn btn-outline-secondary btn-sm edit-student" data-student="<?=e(json_encode($r,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT))?>" data-bs-toggle="modal" data-bs-target="#studentModal">Edit</button></td></tr><?php endforeach;?></tbody></table></div><?php if(!$rows)echo '<div class="empty-state"><i class="bi bi-people"></i><h3 class="heading-6">'.($filtered?'No matching students':'Your directory starts here').'</h3><p>'.($filtered?'Try another search or reset the filters.':'Synchronize eligible students, then assign their buses and drop points.').'</p></div>';pager($total,$page,array_merge(['action'=>'students','q'=>$search],$filters));?></section>
        <div class="modal fade" id="studentModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" action="admin.php?action=update_student"><?php csrf_field();?><input type="hidden" name="student_id" id="edit-student_id"><div class="modal-header"><h2 class="modal-title heading-5" id="modalTitle">Edit transport details</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p id="edit-name"></p><label class="form-label" for="edit-bus_no">Bus number</label><select class="form-select" id="edit-bus_no" name="bus_no" data-routes="<?=e(json_encode($routePoints,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT))?>"><option value="">Unassigned</option><?php foreach($buses as $bus):?><option value="<?=e($bus)?>"><?=e($bus)?></option><?php endforeach;?></select><label class="form-label" for="edit-drop_point">Drop point name</label><select class="form-select" id="edit-drop_point" name="drop_point"><option value="">Select a bus first</option></select><p class="caption-2" id="route-help">Transport details can be edited in every bus status.</p><?php foreach(['bus_status'=>'Bus status'] as $field=>$label):?><label class="form-label" for="edit-<?=$field?>"><?=$label?></label><select class="form-select" id="edit-<?=$field?>" name="<?=$field?>"><option>Active</option><option>Inactive</option><option>Not available</option></select><?php endforeach;?></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save changes</button></div></form></div></div>
    <?php }
} catch(Throwable $ex) { if(!$error)echo '<div class="alert alert-danger">'.e(safe_error($ex)).'</div>'; }
page_end();


function render_faculty_management(PDO $local): void {
    $q=is_string($_GET['q']??null)?substr(trim($_GET['q']),0,100):'';
    $erp=db(true);$params=['%'.$q.'%','%'.$q.'%'];
    $where="f_id LIKE ? OR CONCAT_WS(' ',f_fname,f_lname) LIKE ?";
    $total=(int)query($erp,"SELECT COUNT(*) FROM erp_faculty WHERE $where",$params)->fetchColumn();
    $page=max(1,min((int)($_GET['page']??1),max(1,(int)ceil($total/25))));$offset=($page-1)*25;
    $faculty=query($erp,"SELECT f_id,f_fname,f_lname,f_dept FROM erp_faculty WHERE $where ORDER BY f_fname,f_lname,f_id LIMIT 25 OFFSET $offset",$params)->fetchAll();
    $settings=[];foreach(query($local,'SELECT * FROM faculty_access')->fetchAll() as $setting) $settings[$setting['faculty_id']]=$setting;
    $buses=bus_options($local);
    
    ?><section class="panel"><h2 class="heading-5">Faculty directory</h2><p class="caption-2">Manage access to this application and record faculty bus assignments. ERP records are unchanged. Bus assignments are informational; enabled faculty can mark attendance for all active students.</p>
    <form class="toolbar auto-filter" method="get"><input type="hidden" name="action" value="faculty"><label class="search-field">Search faculty<input class="form-control" name="q" value="<?=e($q)?>" placeholder="Faculty name or ID"></label></form>
    <div class="table-responsive"><table class="table"><thead><tr><th>Faculty ID</th><th>Name</th><th>Department</th><th>App access</th><th>Bus number</th><th>Action</th></tr></thead><tbody>
    <?php foreach($faculty as $index=>$f): $setting=$settings[$f['f_id']]??['access_enabled'=>1,'bus_no'=>null];$form='faculty-form-'.$index;?>
    <tr><td><?=e($f['f_id'])?></td><td><?=e(trim($f['f_fname'].' '.$f['f_lname']))?></td><td><?=e($f['f_dept'])?></td>
    <td><select class="form-select" name="access_enabled" form="<?=$form?>" aria-label="App access for <?=e($f['f_id'])?>"><option value="1" <?=$setting['access_enabled']?'selected':''?>>Enabled</option><option value="0" <?=!$setting['access_enabled']?'selected':''?>>Disabled</option></select></td>
    <td><select class="form-select" name="bus_no" form="<?=$form?>" aria-label="Bus number for <?=e($f['f_id'])?>"><option value="">Unassigned</option><?php foreach($buses as $bus):?><option value="<?=e($bus)?>" <?=$setting['bus_no']===$bus?'selected':''?>><?=e($bus)?></option><?php endforeach;?></select></td>
    <td><form id="<?=$form?>" method="post" action="admin.php?action=update_faculty"><?php csrf_field();?><input type="hidden" name="faculty_id" value="<?=e($f['f_id'])?>"><button class="btn btn-primary btn-sm">Save</button></form></td></tr>
    <?php endforeach;?></tbody></table></div><?php if(!$faculty):?><div class="empty-state"><h3 class="heading-6">No matching faculty</h3><p>Try another faculty name or ID.</p></div><?php endif;pager($total,$page,['action'=>'faculty','q'=>$q]);?></section><?php
}

function render_bus_management(PDO $local): void {
    $edit=is_string($_GET['bus']??null)?$_GET['bus']:'';
    $bus=$edit!==''?query($local,'SELECT * FROM buses WHERE bus_no=?',[$edit])->fetch():false;
    if($edit!=='' && !$bus) throw new RuntimeException('Bus not found.');
    $points=$bus?query($local,'SELECT drop_point FROM bus_drop_points WHERE bus_no=? ORDER BY position',[$edit])->fetchAll(PDO::FETCH_COLUMN):[];
    if($_SERVER['REQUEST_METHOD']==='POST') { $bus=['bus_no'=>is_string($_POST['bus_no']??null)?$_POST['bus_no']:'','driver_name'=>is_string($_POST['driver_name']??null)?$_POST['driver_name']:'','driver_phone'=>is_string($_POST['driver_phone']??null)?$_POST['driver_phone']:''];$edit=is_string($_POST['original_bus']??null)?$_POST['original_bus']:'';$points=is_array($_POST['drop_points']??null)?array_values(array_filter($_POST['drop_points'],'is_string')):[]; }
    $faculty=db(true)->query('SELECT f_id,f_fname,f_lname FROM erp_faculty ORDER BY f_fname,f_lname,f_id')->fetchAll();
    $selectedStaff=$edit!==''?query($local,'SELECT faculty_id FROM faculty_access WHERE bus_no=? ORDER BY faculty_id',[$edit])->fetchAll(PDO::FETCH_COLUMN):[];
    if($_SERVER['REQUEST_METHOD']==='POST')$selectedStaff=is_array($_POST['staff_ids']??null)?array_values(array_filter($_POST['staff_ids'],'is_string')):[];
    $points=array_slice($points?:[''],0,10);$selectedStaff=$selectedStaff?:[''];
    ?><section class="panel"><h2 class="heading-5"><?=$edit!==''?'Edit bus details':'Add a bus'?></h2><form method="post" action="admin.php?action=add_bus"><?php csrf_field();?><input type="hidden" name="original_bus" value="<?=e($edit)?>">
    <div class="bus-form-grid"><label>Bus number<input class="form-control" name="bus_no" maxlength="50" required value="<?=e($bus['bus_no']??'')?>" <?=$edit!==''?'readonly':''?>></label><label>Driver name<input class="form-control" name="driver_name" maxlength="150" required value="<?=e($bus['driver_name']??'')?>"></label><label>Driver phone number<input class="form-control" type="tel" name="driver_phone" maxlength="25" required value="<?=e($bus['driver_phone']??'')?>"></label></div>
    <h3 class="heading-6 mt-4">Drop points</h3><p class="caption-2">Add stops one at a time in route order, up to 10 points.</p>
    <div id="drop-point-list" class="repeat-list" data-max="10"><?php foreach($points as $i=>$point):?><div class="repeat-row"><label><span class="row-label">Drop point <?=$i+1?></span><input class="form-control" name="drop_points[]" maxlength="150" value="<?=e($point)?>" required></label><button type="button" class="btn btn-outline-secondary remove-row">Remove</button></div><?php endforeach;?></div><button type="button" class="btn btn-outline-secondary mt-3 add-row" data-list="drop-point-list">Add another drop point</button>
    <h3 class="heading-6 mt-4">Staff in charge</h3><p class="caption-2">Add one or more faculty members. Selecting someone assigned to another bus moves their assignment here. Existing login access settings are preserved.</p>
    <div id="staff-incharge-list" class="repeat-list" data-max="<?=max(1,count($faculty))?>"><?php foreach($selectedStaff as $i=>$staffId):?><div class="repeat-row"><label><span class="row-label">Staff in charge <?=$i+1?></span><select class="form-select" name="staff_ids[]"><option value="">Select faculty</option><?php foreach($faculty as $f):?><option value="<?=e($f['f_id'])?>" <?=$staffId===$f['f_id']?'selected':''?>><?=e(trim($f['f_fname'].' '.$f['f_lname']).' ('.$f['f_id'].')')?></option><?php endforeach;?></select></label><button type="button" class="btn btn-outline-secondary remove-row">Remove</button></div><?php endforeach;?></div><button type="button" class="btn btn-outline-secondary mt-3 add-row" data-list="staff-incharge-list">Add another staff member</button>
    <div class="toolbar mt-4"><button class="btn btn-primary">Save bus</button><?php if($edit!==''):?><a class="btn btn-outline-secondary" href="admin.php?action=add_bus">Cancel editing</a><?php endif;?></div></form></section>
    <section class="panel"><h2 class="heading-5">Bus directory</h2><p class="caption-2">Assign staff in charge in the bus form above or through <a href="admin.php?action=faculty">Faculty management</a>. Assigned staff can view their bus details on their dashboard.</p>
    <?php $names=[];foreach(db(true)->query('SELECT f_id,f_fname,f_lname FROM erp_faculty')->fetchAll() as $f)$names[$f['f_id']]=trim($f['f_fname'].' '.$f['f_lname']);$buses=query($local,'SELECT * FROM buses ORDER BY bus_no')->fetchAll(); ?>
    <div class="table-responsive"><table class="table"><thead><tr><th>Bus</th><th>Driver</th><th>Phone</th><th>Drop points</th><th>Staff in charge</th><th>Action</th></tr></thead><tbody><?php foreach($buses as $b):$drops=query($local,'SELECT drop_point FROM bus_drop_points WHERE bus_no=? ORDER BY position',[$b['bus_no']])->fetchAll(PDO::FETCH_COLUMN);$staff=query($local,'SELECT faculty_id FROM faculty_access WHERE bus_no=?',[$b['bus_no']])->fetchAll(PDO::FETCH_COLUMN);?><tr><td><?=e($b['bus_no'])?></td><td><?=e($b['driver_name']?:'Not entered')?></td><td><?=e($b['driver_phone']?:'Not entered')?></td><td><?=e(implode(' → ',$drops)?:'Not entered')?></td><td><?=e(implode(', ',array_map(fn($id)=>($names[$id]??'Faculty').' ('.$id.')',$staff))?:'Unassigned')?></td><td><a class="btn btn-outline-secondary btn-sm" href="admin.php?<?=e(http_build_query(['action'=>'add_bus','bus'=>$b['bus_no']]))?>">Edit</a></td></tr><?php endforeach;?></tbody></table></div><?php if(!$buses):?><p class="empty-state">No buses yet. Add your first bus above.</p><?php endif;?></section><?php
}
