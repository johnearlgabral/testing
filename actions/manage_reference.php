<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/password_recovery.php'; // verifyRecoveryCsrf() + logAudit() helpers
header('Content-Type: application/json'); requireRoleApi('super_admin');
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Invalid request method.']); exit; }
$type=$_POST['type']??''; $action=$_POST['action']??'add'; $id=(int)($_POST['id']??0); $name=trim($_POST['name']??'');
$map=['department'=>['departments','department_name'],'course'=>['courses','course_name'],'organization'=>['organizations','organization_name']];
if (!isset($map[$type])) { echo json_encode(['success'=>false,'message'=>'Invalid reference type.']); exit; }
[$table,$column]=$map[$type];
try {
 if ($action==='toggle') { $pdo->prepare("UPDATE $table SET is_active=1-is_active WHERE id=?")->execute([$id]); }
 elseif ($action==='rename' && $id && $name!=='') { $pdo->prepare("UPDATE $table SET $column=? WHERE id=?")->execute([$name,$id]); }
 elseif ($action==='add' && $name!=='') { if ($type==='course') { $departmentId=(int)($_POST['department_id']??0); if(!$departmentId) throw new RuntimeException('Choose a department.'); $pdo->prepare('INSERT INTO courses (department_id,course_name) VALUES (?,?)')->execute([$departmentId,$name]); } elseif($type==='organization') { $departmentId=(int)($_POST['department_id']??0); $pdo->prepare('INSERT INTO organizations (department_id,organization_name) VALUES (?,?)')->execute([$departmentId?:null,$name]); } else $pdo->prepare('INSERT INTO departments (department_name) VALUES (?)')->execute([$name]); }
 elseif ($action==='delete' && $id) {
     // Deleting is the only destructive path on this endpoint, so it carries a
     // scoped per-session CSRF check (same pattern as the password-recovery /
     // correction-request admin actions).
     if (!verifyRecoveryCsrf($_POST['csrf'] ?? '')) {
         echo json_encode(['success'=>false,'message'=>'Security check failed. Refresh the page and try again.']); exit;
     }
     // Load the record first so the delete is verified by name, never by blind id.
     $stmt=$pdo->prepare("SELECT $column AS record_name FROM $table WHERE id=?");
     $stmt->execute([$id]); $recordName=$stmt->fetchColumn();
     if ($recordName===false) { echo json_encode(['success'=>false,'message'=>'That record no longer exists.']); exit; }

     // Server-side dependency checks that mirror the real foreign-key graph:
     //   departments <- users, courses, organizations
     //   courses     <- users
     //   organizations <- users, activities
     // A referenced record is BLOCKED (never cascaded, never silently detached);
     // the message tells the admin exactly what still references it and points
     // to the existing Deactivate action instead.
     $refs=[];
     if ($type==='department') {
         $q=$pdo->prepare('SELECT (SELECT COUNT(*) FROM users WHERE department_id=?) AS u,(SELECT COUNT(*) FROM courses WHERE department_id=?) AS c,(SELECT COUNT(*) FROM organizations WHERE department_id=?) AS o');
         $q->execute([$id,$id,$id]); $r=$q->fetch();
         if((int)$r['u']) $refs[]=(int)$r['u'].' user account'.((int)$r['u']===1?'':'s');
         if((int)$r['c']) $refs[]=(int)$r['c'].' course'.((int)$r['c']===1?'':'s');
         if((int)$r['o']) $refs[]=(int)$r['o'].' organization'.((int)$r['o']===1?'':'s');
     } elseif ($type==='course') {
         $q=$pdo->prepare('SELECT COUNT(*) FROM users WHERE course_id=?'); $q->execute([$id]); $n=(int)$q->fetchColumn();
         if($n) $refs[]=$n.' user account'.($n===1?'':'s');
     } elseif ($type==='organization') {
         $q=$pdo->prepare('SELECT (SELECT COUNT(*) FROM users WHERE organization_id=?) AS u,(SELECT COUNT(*) FROM activities WHERE organization_id=?) AS a');
         $q->execute([$id,$id]); $r=$q->fetch();
         if((int)$r['u']) $refs[]=(int)$r['u'].' user account'.((int)$r['u']===1?'':'s');
         if((int)$r['a']) $refs[]=(int)$r['a'].' activit'.((int)$r['a']===1?'y':'ies');
     }
     if ($refs) {
         echo json_encode(['success'=>false,'message'=>'Cannot delete "'.strip_tags($recordName).'": '.implode(', ',$refs).' still reference '.(count($refs)===1?'it':'them').'. Deactivate it instead - inactive records are hidden from registration and report filters while every existing account, course, organization, and activity keeps its data intact.']);
         exit;
     }

     $pdo->prepare("DELETE FROM $table WHERE id=?")->execute([$id]);
     $labelMap=['department'=>'Department','course'=>'Course','organization'=>'Organization'];
     logAudit('Deleted '.$labelMap[$type], 'reference', $id, $recordName, null, 'Deleted');
 }
 else throw new RuntimeException('Provide the required information.');
 echo json_encode(['success'=>true]);
} catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$action==='delete'?'Could not delete this record - it may have just been referenced by a new account, course, or activity. Refresh and try again.':'Could not save the official list entry. It may already exist.']); }
