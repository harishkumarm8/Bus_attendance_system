<?php
require __DIR__.'/config.php';require_role('staff');
$action=is_string($_GET['action']??null)?$_GET['action']:'dashboard';
if(!in_array($action,['dashboard','attendance','history','filter','mark','edit'],true)) {http_response_code(404);page_start('Page not found','staff');echo '<a href="staff.php">Back to dashboard</a>';page_end();exit;}
$error='';$date=date('Y-m-d');
try {
    $local=db();
    if(in_array($action,['mark','edit'],true)) {
        check_post();$date=date_value($_POST['date']??'');$id=input('student_id',64);$status=input('status',10);
        if(!in_array($status,['Present','Absent'],true)) throw new RuntimeException('Choose Present or Absent.');
        $local->beginTransaction();
        try {
            $student=query($local,"SELECT student_id FROM students WHERE student_id=? AND bus_status='Active' AND NULLIF(TRIM(drop_point),'') IS NOT NULL FOR UPDATE",[$id])->fetch();
            if(!$student) throw new RuntimeException('This student does not have an active bus assignment and a drop point name.');
            store_put($local,'attendance',['student_id'=>$id,'attendance_date'=>$date,'attendance_status'=>$status,'staff_user_id'=>$_SESSION['user_id']],['attendance_status','staff_user_id']);
            $local->commit();
        } catch(Throwable $ex) {$local->rollBack();throw $ex;}
        flash('Attendance saved as '.$status.' for '.$date.'.');go('staff.php?'.http_build_query(['action'=>'attendance','date'=>$date,'q'=>input('q',100,false),'page'=>max(1,(int)($_POST['page']??1))]));
    }
    $date=date_value($_GET['date']??date('Y-m-d'));
} catch(Throwable $ex) {$error=safe_error($ex);}
$history=$action==='history';page_start($history?'Your attendance history':'Daily bus attendance','staff',$action==='filter'?'attendance':$action);
if($error) echo '<div class="alert alert-danger" role="alert">'.e($error).'</div>';
try {
    $local=db();
    $assigned=query($local,'SELECT b.* FROM faculty_access f JOIN buses b ON b.bus_no=f.bus_no WHERE f.faculty_id=?',[$_SESSION['user_id']])->fetch();
    if($assigned) {
        $stops=query($local,'SELECT drop_point FROM bus_drop_points WHERE bus_no=? ORDER BY position',[$assigned['bus_no']])->fetchAll(PDO::FETCH_COLUMN);
        echo '<section class="panel"><h2 class="heading-5">Your bus: '.e($assigned['bus_no']).'</h2><p><strong>Driver:</strong> '.e($assigned['driver_name']?:'Not entered').' · <strong>Phone:</strong> '.e($assigned['driver_phone']?:'Not entered').'</p><h3 class="heading-6">Drop points</h3><p>'.e(implode(' → ',$stops)?:'No drop points entered yet.').'</p></section>';
    }
    $search=is_string($_GET['q']??null)?substr(trim($_GET['q']),0,100):'';
    $summary=query($local,"SELECT COUNT(*) total,COALESCE(SUM(a.attendance_status='Present'),0) present,COALESCE(SUM(a.attendance_status='Absent'),0) absent,COALESCE(SUM(a.id IS NULL),0) unmarked FROM students s LEFT JOIN attendance a ON a.student_id=s.student_id AND a.attendance_date=? WHERE s.bus_status='Active' AND NULLIF(TRIM(s.drop_point),'') IS NOT NULL",[$date])->fetch();
    stats(['Eligible students'=>$summary['total'],'Present'=>$summary['present'],'Absent'=>$summary['absent'],'Not yet marked'=>$summary['unmarked']]);
    $from='FROM students s LEFT JOIN attendance a ON a.student_id=s.student_id AND a.attendance_date=?';$params=[$date];
    $where=$history?'a.staff_user_id=?':"s.bus_status='Active' AND NULLIF(TRIM(s.drop_point),'') IS NOT NULL";
    if($history)$params[]=$_SESSION['user_id'];
    $where.=' AND (s.student_name LIKE ? OR s.reg_no LIKE ?)';$params[]='%'.$search.'%';$params[]='%'.$search.'%';
    $total=(int)query($local,"SELECT COUNT(*) $from WHERE $where",$params)->fetchColumn();$page=max(1,min((int)($_GET['page']??1),max(1,(int)ceil($total/25))));$offset=($page-1)*25;
    $rows=query($local,"SELECT s.*,a.attendance_status $from WHERE $where ORDER BY s.student_name,s.student_id LIMIT 25 OFFSET $offset",$params)->fetchAll();
?>
<section class="panel"><div class="section-title"><div><h2 class="heading-5"><?=$history?'Records last marked by you':'Student attendance'?></h2><p class="caption-2"><?=$history?'Select a date to review records where you made the latest attendance update.':'Choose a date, then mark each student present or absent.'?></p></div><span class="status pending"><?=e($date)?></span></div><form class="toolbar auto-filter" method="get"><input type="hidden" name="action" value="<?=$history?'history':'attendance'?>"><label>Attendance date<input class="form-control" type="date" name="date" value="<?=e($date)?>" min="2000-01-01" max="<?=date('Y-m-d')?>" required></label><label class="search-field">Search students<input class="form-control" name="q" maxlength="100" placeholder="Name or register number" value="<?=e($search)?>"></label></form><p class="caption-2">Summary counts cover all currently active assignments for the selected date. Attendance is shared between eligible faculty; the latest editor is recorded.</p>
<div class="table-responsive"><table class="table"><thead><tr><th>Student ID</th><th>Student name</th><th>Register number</th><th>Year</th><th>Department</th><th>Bus</th><th>Drop point name</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['student_id'])?></td><td><strong><?=e($r['student_name'])?></strong></td><td><?=e($r['reg_no'])?></td><td><?=e($r['year']??'Unassigned')?></td><td><?=e($r['dept']?:'Unassigned')?></td><td><?=e($r['bus_no']?:'Unassigned')?></td><td><?=e($r['drop_point']?:'Unassigned')?></td><td><?=badge($r['attendance_status'])?></td><td><?php if($r['bus_status']==='Active' && trim((string)$r['drop_point'])!==''):?><form class="attendance-actions" method="post" action="staff.php?action=<?=$r['attendance_status']?'edit':'mark'?>"><?php csrf_field();?><input type="hidden" name="student_id" value="<?=e($r['student_id'])?>"><input type="hidden" name="date" value="<?=e($date)?>"><input type="hidden" name="q" value="<?=e($search)?>"><input type="hidden" name="page" value="<?=$page?>"><button class="btn btn-sm btn-present" name="status" value="Present" aria-label="Mark <?=e($r['student_name'])?> present" aria-pressed="<?=$r['attendance_status']==='Present'?'true':'false'?>"><i class="bi bi-check2" aria-hidden="true"></i> Present</button><button class="btn btn-sm btn-absent" name="status" value="Absent" aria-label="Mark <?=e($r['student_name'])?> absent" aria-pressed="<?=$r['attendance_status']==='Absent'?'true':'false'?>"><i class="bi bi-x" aria-hidden="true"></i> Absent</button></form><?php else:?>Inactive assignment<?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
<?php if(!$rows):?><div class="empty-state"><i class="bi bi-calendar2-check" aria-hidden="true"></i><h3 class="heading-6"><?=$history?'No records for this date':'No students to display'?></h3><p><?=$history?'Try another date to find records you last updated.':'Try a different search, or ask your admin to synchronize students and activate their transport assignments.'?></p></div><?php endif;pager($total,$page,['action'=>$history?'history':'attendance','date'=>$date,'q'=>$search]);?></section>
<?php } catch(Throwable $ex) {if(!$error) echo '<div class="alert alert-danger">'.e(safe_error($ex)).'</div>';}page_end();
