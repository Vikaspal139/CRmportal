<?php

/**
 * DDVM College - STAFF PORTAL (Single File)
 * File: staff_dashboard.php
 * Role: STAFF (B)
 *
 * FIXED:
 * - Removed missing db_connection.php include (was causing HTTP 500)
 * - Unified session/auth with config.php (uses $_SESSION['user'])
 * - Removed premature PHP closing tag that broke execution
 */

require_once __DIR__ . "/config.php";

// ===== DEBUG/LOGGING (prevents blank HTTP 500) =====
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/staff_dashboard_error.log');
error_reporting(E_ALL);

set_error_handler(function($severity, $message, $file, $line){
  error_log("[STAFF_DASHBOARD] PHP {$severity}: {$message} in {$file}:{$line}");
  return false;
});
set_exception_handler(function($e){
  error_log("[STAFF_DASHBOARD] EXCEPTION: ".$e->getMessage()." in ".$e->getFile().":".$e->getLine());
  http_response_code(500);
  echo "<h3 style='font-family:Arial'>Staff portal error (500)</h3>";
  echo "<p style='font-family:Arial'>Please check <b>staff_dashboard_error.log</b> in the same folder for the exact reason.</p>";
  exit;
});
if (function_exists('mysqli_report')) { mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); }

// Require STAFF role (config.php uses $_SESSION['user']['role'])
require_role(["STAFF"]);

$conn = db();

$u = current_user();
$name = $u["full_name"] ?? ($u["name"] ?? "Staff");
$role = strtoupper($u["role"] ?? "STAFF");

/************************************************************
 * DDVM College - STAFF PORTAL (Single File)
 * File: staff_dashboard.php
 * Role: STAFF (B)
 *
 * Theme + top horizontal menu is COMMON for all users.
 * Only menu names / modules will differ in other dashboards.
 ************************************************************/

// CSRF
if (empty($_SESSION["csrf_staff"])) {
  $_SESSION["csrf_staff"] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION["csrf_staff"];

// Helpers
function safe_get($k, $d=""){ return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d; }
function safe_post($k, $d=""){ return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }
function is_post(){ return ($_SERVER["REQUEST_METHOD"] ?? "") === "POST"; }
function go($url){ header("Location: ".$url); exit; }


// DB helpers
function db_has_column($conn, $table, $col){
  $table = preg_replace('/[^a-zA-Z0-9_]/','', $table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/','', $col);
  $q = "SHOW COLUMNS FROM `{$table}` LIKE '{$col}'";
  $r = $conn->query($q);
  if ($r && $r->num_rows > 0) { $r->free(); return true; }
  if ($r) { $r->free(); }
  return false;
}

function db_has_table($conn, $table){
  $table = preg_replace('/[^a-zA-Z0-9_]/','', $table);
  $q = "SHOW TABLES LIKE '{$table}'";
  $r = $conn->query($q);
  if ($r && $r->num_rows > 0) { $r->free(); return true; }
  if ($r) { $r->free(); }
  return false;
}

// Menu (Horizontal)
$H = (int)safe_get("h", "1");   // 1..7
if ($H < 1 || $H > 7) $H = 1;

// Vertical per Horizontal (default first item)
$V = (int)safe_get("v", "1");
if ($V < 1) $V = 1;

// Flash
$flash = $_SESSION["flash_staff"] ?? null;
unset($_SESSION["flash_staff"]);
function flash_set($type, $msg){ $_SESSION["flash_staff"] = ["type"=>$type, "msg"=>$msg]; }

// ============================
// DB CONFIG (Tables)
// ============================
$T_ENQ  = "admission_enquiry";
$T_ADM  = "admission_enquiry";
$T_META = null; // deprecated: using admission_enquiry columns total_fees / staff_remark
$T_NOTICE = "notice_news";
$T_READS  = "notice_news_reads";
$T_SLIDER = "website_slider_images";
$T_MSG    = "student_direct_messages";
$T_FEE_PAYMENTS = "fee_payments";
$T_LIBRARY = "library_books";
$T_UPLOADS = "site_uploads";
$T_ECLASS = "e_class_videos";

// AJAX: View full admission form (used in B,2,4 and B,2,5)
if(isset($_GET["ajax"]) && $_GET["ajax"]==="admission"){
  header("Content-Type: application/json; charset=utf-8");
  $id = (int)($_GET["id"] ?? 0);
  if($id<=0){ echo json_encode(["ok"=>false,"error"=>"Invalid id"]); exit; }
  $stmt = $conn->prepare("SELECT * FROM `$T_ENQ` WHERE id=? LIMIT 1");
  $row = null;
  if($stmt){
    $stmt->bind_param("i",$id);
    $stmt->execute();
    $res=$stmt->get_result();
    $row=$res?$res->fetch_assoc():null;
    $stmt->close();
  }
  if(!$row){ echo json_encode(["ok"=>false,"error"=>"Not found"]); exit; }
  $docs=[];
  foreach($row as $k=>$v){
    if(is_string($v) && trim($v)!==""){
      $vv=trim($v);
      if(strpos($vv,"/")!==false || preg_match("/\.(pdf|jpg|jpeg|png)$/i",$vv)){ $docs[$k]=$vv; }
    }
  }
  echo json_encode(["ok"=>true,"data"=>$row,"docs"=>$docs]);
  exit;
}


// Notice/News column compatibility (some DBs use `type`, some use `kind`, some have both)
$NOTICE_COL_PRIMARY = db_has_column($conn, $T_NOTICE, "type") ? "type" : (db_has_column($conn, $T_NOTICE, "kind") ? "kind" : "type");
$NOTICE_COL_SECOND  = null;
if ($NOTICE_COL_PRIMARY === "type" && db_has_column($conn, $T_NOTICE, "kind")) $NOTICE_COL_SECOND = "kind";
if ($NOTICE_COL_PRIMARY === "kind" && db_has_column($conn, $T_NOTICE, "type")) $NOTICE_COL_SECOND = "type";

// Target enum compatibility: WEBSITE / STUDENT_PORTAL / BOTH
function normalize_notice_target($t){
  $t = strtoupper(trim((string)$t));
  if ($t === "PORTAL") return "STUDENT_PORTAL";
  if ($t === "STUDENT") return "STUDENT_PORTAL";
  if ($t === "STUDENT_PORTAL") return "STUDENT_PORTAL";
  if ($t === "WEBSITE") return "WEBSITE";
  return "BOTH";
}

function upsert_site_notice($conn, $title, $body, $ndate, $target, $is_popup, $is_scroll, $is_active, $createdBy){
  if (!db_has_table($conn, "site_notices")) return;

  $title = trim((string)$title);
  $body = trim((string)$body);
  $ndate = trim((string)$ndate);
  $target = normalize_notice_target($target);
  $createdBy = (int)$createdBy;

  $existingId = 0;
  $stmt = $conn->prepare("SELECT id FROM site_notices WHERE title=? AND publish_from=? ORDER BY id DESC LIMIT 1");
  if ($stmt) {
    $stmt->bind_param("ss", $title, $ndate);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $existingId = $row ? (int)$row["id"] : 0;
    $stmt->close();
  }

  if ($existingId > 0) {
    $stmt = $conn->prepare("UPDATE site_notices SET title=?, body=?, target=?, is_popup=?, is_scrolling=?, is_active=?, publish_from=?, updated_at=NOW() WHERE id=? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("sssiiisi", $title, $body, $target, $is_popup, $is_scroll, $is_active, $ndate, $existingId);
      $stmt->execute();
      $stmt->close();
    }
    return;
  }

  $stmt = $conn->prepare("INSERT INTO site_notices (title, body, target, is_popup, is_scrolling, is_active, publish_from, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?, ?, NOW(), NOW())");
  if ($stmt) {
    $stmt->bind_param("sssiiisi", $title, $body, $target, $is_popup, $is_scroll, $is_active, $ndate, $createdBy);
    $stmt->execute();
    $stmt->close();
  }
}

function admission_doc_candidates($row, $baseDir){
  $files = [];
  foreach ($row as $k => $v) {
    if (!is_string($v) || trim($v) === "") continue;
    $vv = trim($v);
    $lk = strtolower((string)$k);
    $isDocKey = (strpos($lk, "doc_") === 0)
      || strpos($lk, "doc") !== false
      || strpos($lk, "photo") !== false
      || strpos($lk, "sign") !== false
      || strpos($lk, "aadhar") !== false
      || strpos($lk, "aadhaar") !== false
      || strpos($lk, "apaar") !== false
      || strpos($lk, "apar") !== false;
    $hasExt = preg_match("/\.(pdf|jpg|jpeg|png|webp)$/i", $vv) === 1;
    if (!$isDocKey && !$hasExt) continue;
    $paths = [];
    $paths[] = $vv;
    $paths[] = $baseDir."/".ltrim($vv,"/");
    $paths[] = $baseDir."/uploads/".basename($vv);
    foreach ($paths as $p) {
      $real = @realpath($p);
      if ($real && is_file($real)) {
        $files[] = $real;
        break;
      }
    }
  }
  return array_values(array_unique($files));
}

function admission_html_summary($row){
  $title = htmlspecialchars($row["student_name"] ?? "Admission");
  $rows = "";
  foreach ($row as $k => $v) {
    $key = htmlspecialchars((string)$k);
    $val = htmlspecialchars((string)$v);
    $rows .= "<tr><th>{$key}</th><td>{$val}</td></tr>";
  }
  return "<!doctype html><html><head><meta charset='utf-8'><title>{$title}</title>
  <style>body{font-family:Arial,sans-serif;margin:20px}table{border-collapse:collapse;width:100%}
  th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f3f4f6}</style>
  </head><body><h2>Admission Form</h2><table>{$rows}</table></body></html>";
}


// ============================
// ACTION HANDLERS (POST)
// ============================
if (is_post()) {
  $post_csrf = safe_post("csrf", "");
  if (!$post_csrf || !hash_equals($csrf, $post_csrf)) {
    flash_set("bad", "Session expired. Please refresh and try again.");
    go("staff_dashboard.php?h=".$H."&v=".$V);
  }

  $action = safe_post("action", "");
  $id = (int)safe_post("id", "0"); // admission_id OR record id (depends on action)

  // Admission actions require valid admission id
  if (in_array($action, ["reject","accept","verify"], true) && $id <= 0) {
    flash_set("bad", "Invalid admission id.");
    go("staff_dashboard.php?h=2&v=1");
  }

  // 1) REJECT
  if ($action === "reject") {
    $remark = safe_post("remark", "");
    if ($remark === "") {
      flash_set("bad", "Reject reason/remark is required.");
      go("staff_dashboard.php?h=".$H."&v=".$V);
    }

    // Save into admission_enquiry (no meta table)
    $stmt = $conn->prepare("UPDATE `$T_ENQ` SET status='REJECTED', staff_remark=? WHERE id=? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("si", $remark, $id);
      $stmt->execute();
      $stmt->close();
      $row = null;
      $st2 = $conn->prepare("SELECT student_name,email,reg_no,course,semester_apply,session FROM `$T_ENQ` WHERE id=? LIMIT 1");
      if ($st2) {
        $st2->bind_param("i",$id);
        $st2->execute();
        $res = $st2->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $st2->close();
      }
      if ($row && !empty($row["email"])) {
        $to = $row["email"];
        $sub = "DDVM Admission Rejected";
        $nm  = htmlspecialchars($row["student_name"] ?? "Student");
        $reg = htmlspecialchars($row["reg_no"] ?? "");
        $course = htmlspecialchars($row["course"] ?? "");
        $sem = htmlspecialchars($row["semester_apply"] ?? "");
        $sess= htmlspecialchars($row["session"] ?? "");
        $rmk = htmlspecialchars($remark);
        $html = "<p>Dear {$nm},</p>
                 <p>Your admission form has been <b>REJECTED</b>.</p>
                 <p><b>Reg No:</b> {$reg}<br><b>Course:</b> {$course}<br><b>Semester:</b> {$sem}<br><b>Session:</b> {$sess}</p>
                 <p><b>Reason:</b> {$rmk}</p>
                 <p>For further assistance, please contact the college.</p>
                 <p>Thank you,<br>DDVM College</p>";
        @send_mail_html($to,$sub,$html);
      }
      flash_set("ok", "Admission rejected successfully.");
    } else {
      flash_set("bad", "DB error: unable to reject.");
    }
    go("staff_dashboard.php?h=2&v=1");
  }

  // 2) ACCEPT
  // (requires total fees + remark -> save into meta table + mark ACCEPTED)
  if ($action === "accept") {
    $total_fees = safe_post("total_fees", "");
    $remark = safe_post("remark", "");
    if ($total_fees === "" || $remark === "") {
      flash_set("bad", "Total Fees and Remark both are required to accept.");
      go("staff_dashboard.php?h=".$H."&v=".$V);
    }

    $tf = (float)$total_fees;
    if ($tf < 0) $tf = 0;

    // Update admission_enquiry directly
    $stmt = $conn->prepare("UPDATE `$T_ENQ` 
      SET status='ACCEPTED',
          total_fees=?,
          staff_remark=?,
          fees_paid=IFNULL(fees_paid,0),
          fees_due=GREATEST(?,0),
          accepted_at=NOW()
      WHERE id=? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("dsdi", $tf, $remark, $tf, $id);
      $stmt->execute();
      $stmt->close();

      // Email to student (best-effort)
      $row = null;
      $st2 = $conn->prepare("SELECT student_name,email,reg_no,course,semester_apply,session FROM `$T_ENQ` WHERE id=? LIMIT 1");
      if ($st2) {
        $st2->bind_param("i",$id);
        $st2->execute();
        $res = $st2->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $st2->close();
      }
      if ($row && !empty($row["email"])) {
        $to = $row["email"];
        $sub = "DDVM Admission Accepted";
        $nm  = htmlspecialchars($row["student_name"] ?? "Student");
        $reg = htmlspecialchars($row["reg_no"] ?? "");
        $course = htmlspecialchars($row["course"] ?? "");
        $sem = htmlspecialchars($row["semester_apply"] ?? "");
        $sess= htmlspecialchars($row["session"] ?? "");
        $html = "<p>Dear {$nm},</p>
                 <p>Your admission form has been <b>ACCEPTED</b>.</p>
                 <p><b>Reg No:</b> {$reg}<br><b>Course:</b> {$course}<br><b>Semester:</b> {$sem}<br><b>Session:</b> {$sess}</p>
                 <p>Please pay your due fees or contact the college / visit the office within <b>7 days</b>; otherwise your form will not be verified.</p>
                 <p>Thank you,<br>DDVM College</p>";
        @send_mail_html($to,$sub,$html);
      }

      flash_set("ok", "Admission accepted successfully.");
    } else {
      flash_set("bad", "DB error: unable to accept.");
    }
    go("staff_dashboard.php?h=2&v=2");
  }

  // 3) VERIFY
  // (mark VERIFIED + create student user)
  if ($action === "verify") {
    $stmt = $conn->prepare("UPDATE `$T_ENQ` SET status='VERIFIED' WHERE id=? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("i", $id);
      $stmt->execute();
      $stmt->close();
      $row = null;
      $st2 = $conn->prepare("SELECT student_name,email,reg_no FROM `$T_ENQ` WHERE id=? LIMIT 1");
      if ($st2) {
        $st2->bind_param("i",$id);
        $st2->execute();
        $res = $st2->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $st2->close();
      }
      if ($row && !empty($row["email"])) {
        $to = $row["email"];
        $sub = "DDVM Admission Verified - Create Your Login";
        $nm  = htmlspecialchars($row["student_name"] ?? "Student");
        $reg = htmlspecialchars($row["reg_no"] ?? "");
        $html = "<p>Dear {$nm},</p>
                 <p>Your admission has been <b>VERIFIED</b>.</p>
                 <p><b>Reg No:</b> {$reg}</p>
                 <p>Please create your Student Portal password using <b>Forgot Password</b> on the login page (use this same email).</p>
                 <p>Thank you,<br>DDVM College</p>";
        @send_mail_html($to,$sub,$html);
      }
      flash_set("ok", "Admission verified successfully.");
    } else {
      flash_set("bad", "DB error: unable to verify.");
    }
    go("staff_dashboard.php?h=2&v=3");
  }

  if ($action === "accept_update") {
    if ($id <= 0) {
      flash_set("bad", "Invalid admission id.");
      go("staff_dashboard.php?h=2&v=2");
    }
    $total_fees = safe_post("total_fees", "");
    $remark = safe_post("remark", "");
    if ($total_fees === "" || $remark === "") {
      flash_set("bad", "Total Fees and Remark both are required.");
      go("staff_dashboard.php?h=2&v=2");
    }
    $tf = (float)$total_fees;
    if ($tf < 0) $tf = 0;
    $stmt = $conn->prepare("UPDATE `$T_ENQ` SET total_fees=?, staff_remark=?, fees_due=GREATEST(? - IFNULL(fees_paid,0),0) WHERE id=? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("dsdi", $tf, $remark, $tf, $id);
      $stmt->execute();
      $stmt->close();
      flash_set("ok", "Fees and remark updated.");
    } else {
      flash_set("bad", "DB error: unable to update fees/remark.");
    }
    go("staff_dashboard.php?h=2&v=2");
  }

  if ($action === "admission_update") {
    if ($id <= 0) {
      flash_set("bad", "Invalid admission id.");
      go("staff_dashboard.php?h=2&v=5");
    }
    $student_name = safe_post("student_name", "");
    $mobile = safe_post("mobile", "");
    $email = safe_post("email", "");
    $course = safe_post("course", "");
    $semester_apply = safe_post("semester_apply", "");
    $session = safe_post("session", "");
    $status = strtoupper(safe_post("status", ""));
    $allowedStatus = ["NEW","ACCEPTED","VERIFIED","REJECTED","REGISTERED"];
    if (!in_array($status, $allowedStatus, true)) {
      $status = "";
    }

    $stmt = $conn->prepare("UPDATE `$T_ENQ` SET student_name=?, mobile=?, email=?, course=?, semester_apply=?, session=?, status=? WHERE id=? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("sssssssi", $student_name, $mobile, $email, $course, $semester_apply, $session, $status, $id);
      $stmt->execute();
      $stmt->close();
      flash_set("ok", "Admission updated successfully.");
    } else {
      flash_set("bad", "DB error: unable to update admission.");
    }
    go("staff_dashboard.php?h=2&v=5&edit=".$id);
  }

  if ($action === "fee_collect") {
    if ($id <= 0) {
      flash_set("bad", "Invalid admission id.");
      go("staff_dashboard.php?h=3&v=2");
    }
    $amount = (float)safe_post("amount", "0");
    $pay_mode = safe_post("pay_mode", "CASH");
    $txn_id = safe_post("txn_id", "");
    $pay_date = safe_post("pay_date", date("Y-m-d"));
    if ($amount <= 0) {
      flash_set("bad", "Payment amount must be greater than zero.");
      go("staff_dashboard.php?h=3&v=2");
    }
            $st = $conn->prepare("SELECT email, student_name, reg_no, fees_paid, total_fees FROM `$T_ENQ` WHERE id=? LIMIT 1");
    $row = null;
    if ($st) {
      $st->bind_param("i", $id);
      $st->execute();
      $res = $st->get_result();
      $row = $res ? $res->fetch_assoc() : null;
      $st->close();
    }
    $email = $row["email"] ?? "";
    $stmt = $conn->prepare("INSERT INTO `$T_FEE_PAYMENTS` (admission_id, student_email, amount, pay_mode, txn_id, pay_date, status, created_at) VALUES (?,?,?,?,?,?, 'PAID', NOW())");
    if ($stmt) {
      $stmt->bind_param("isdsss", $id, $email, $amount, $pay_mode, $txn_id, $pay_date);
      $stmt->execute();
      $stmt->close();
    }
    $stmt = $conn->prepare("UPDATE `$T_ENQ` SET fees_paid=IFNULL(fees_paid,0)+?, fees_due=GREATEST(IFNULL(total_fees,0)- (IFNULL(fees_paid,0)+?),0) WHERE id=? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("ddi", $amount, $amount, $id);
      $stmt->execute();
      $stmt->close();
      flash_set("ok", "Fee payment recorded.");
    } else {
      flash_set("bad", "DB error: unable to update fees.");
    }
    if (!empty($email)) {
      $nm = htmlspecialchars($row["student_name"] ?? "Student");
      $reg = htmlspecialchars($row["reg_no"] ?? "");
      $paid = htmlspecialchars(number_format($amount, 2));
      $date = htmlspecialchars($pay_date);
      $html = "<p>Dear {$nm},</p>
               <p>Please find the fee receipt for your recent payment.</p>
               <p><b>Reg No:</b> {$reg}<br><b>Amount Paid:</b> {$paid}<br><b>Date:</b> {$date}</p>
               <p>Thank you,<br>DDVM College</p>";
      @send_mail_html($email, "DDVM Fee Receipt", $html);
    }
    go("staff_dashboard.php?h=3&v=2");
  }

  if ($action === "library_upload") {
    $subject = safe_post("subject", "");
    $semester = (int)safe_post("semester", "0");
    if ($subject === "" || $semester < 1) {
      flash_set("bad", "Subject and semester are required.");
      go("staff_dashboard.php?h=6&v=1");
    }
    if (empty($_FILES["library_file"]["name"])) {
      flash_set("bad", "Please choose a file to upload.");
      go("staff_dashboard.php?h=6&v=1");
    }
    $upDir = __DIR__ . "/uploads/library";
    if (!is_dir($upDir)) { @mkdir($upDir, 0755, true); }
    $orig = basename($_FILES["library_file"]["name"]);
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ["pdf","png","jpg","jpeg","webp"];
    if (!in_array($ext, $allowed, true)) {
      flash_set("bad", "Allowed files: PDF/JPG/PNG/WEBP.");
      go("staff_dashboard.php?h=6&v=1");
    }
    $fname = "library_".date("Ymd_His")."_".bin2hex(random_bytes(3)).".".$ext;
    $destAbs = $upDir . "/" . $fname;
    if (!@move_uploaded_file($_FILES["library_file"]["tmp_name"], $destAbs)) {
      flash_set("bad", "Upload failed.");
      go("staff_dashboard.php?h=6&v=1");
    }
    $path = "uploads/library/".$fname;
    if (db_has_table($conn, $T_LIBRARY)) {
      $stmt = $conn->prepare("INSERT INTO `$T_LIBRARY` (subject, semester, file_path, file_type, is_active, created_at) VALUES (?,?,?,?,1,NOW())");
      if ($stmt) {
        $type = strtoupper($ext);
        $stmt->bind_param("siss", $subject, $semester, $path, $type);
        $stmt->execute();
        $stmt->close();
      }
    } elseif (db_has_table($conn, $T_UPLOADS)) {
      $stmt = $conn->prepare("INSERT INTO `$T_UPLOADS` (file_name, file_path, file_type, tag, sort_order, is_active, created_at) VALUES (?,?,?,?,?,1,NOW())");
      if ($stmt) {
        $type = strtoupper($ext);
        $stmt->bind_param("ssssi", $orig, $path, $type, $subject, $semester);
        $stmt->execute();
        $stmt->close();
      }
    }
    flash_set("ok", "Library file uploaded.");
    go("staff_dashboard.php?h=6&v=2");
  }

  if ($action === "library_update") {
    $lib_id = (int)safe_post("lib_id", "0");
    $subject = safe_post("subject", "");
    $semester = (int)safe_post("semester", "0");
    if ($lib_id <= 0 || $subject === "" || $semester < 1) {
      flash_set("bad", "Invalid update data.");
      go("staff_dashboard.php?h=6&v=2");
    }
    if (db_has_table($conn, $T_LIBRARY)) {
      $stmt = $conn->prepare("UPDATE `$T_LIBRARY` SET subject=?, semester=? WHERE id=? LIMIT 1");
      if ($stmt) {
        $stmt->bind_param("sii", $subject, $semester, $lib_id);
        $stmt->execute();
        $stmt->close();
      }
    } elseif (db_has_table($conn, $T_UPLOADS)) {
      $stmt = $conn->prepare("UPDATE `$T_UPLOADS` SET tag=?, sort_order=? WHERE id=? LIMIT 1");
      if ($stmt) {
        $stmt->bind_param("sii", $subject, $semester, $lib_id);
        $stmt->execute();
        $stmt->close();
      }
    }
    flash_set("ok", "Library record updated.");
    go("staff_dashboard.php?h=6&v=2");
  }

  if ($action === "library_delete") {
    $lib_id = (int)safe_post("lib_id", "0");
    if ($lib_id <= 0) {
      flash_set("bad", "Invalid library record.");
      go("staff_dashboard.php?h=6&v=2");
    }
    $path = "";
    if (db_has_table($conn, $T_LIBRARY)) {
      $stmt = $conn->prepare("SELECT file_path FROM `$T_LIBRARY` WHERE id=? LIMIT 1");
      if ($stmt) {
        $stmt->bind_param("i", $lib_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $path = $row["file_path"] ?? "";
        $stmt->close();
      }
      $stmt = $conn->prepare("DELETE FROM `$T_LIBRARY` WHERE id=? LIMIT 1");
      if ($stmt) { $stmt->bind_param("i", $lib_id); $stmt->execute(); $stmt->close(); }
    } elseif (db_has_table($conn, $T_UPLOADS)) {
      $stmt = $conn->prepare("SELECT file_path FROM `$T_UPLOADS` WHERE id=? LIMIT 1");
      if ($stmt) {
        $stmt->bind_param("i", $lib_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $path = $row["file_path"] ?? "";
        $stmt->close();
      }
      $stmt = $conn->prepare("DELETE FROM `$T_UPLOADS` WHERE id=? LIMIT 1");
      if ($stmt) { $stmt->bind_param("i", $lib_id); $stmt->execute(); $stmt->close(); }
    }
    if ($path !== "") {
      $abs = __DIR__ . "/" . ltrim($path, "/");
      if (is_file($abs)) { @unlink($abs); }
    }
    flash_set("ok", "Library record deleted.");
    go("staff_dashboard.php?h=6&v=2");
  }

  if ($action === "eclass_upload") {
    $subject = safe_post("subject", "");
    $semester = (int)safe_post("semester", "0");
    $unit_no = safe_post("unit_no", "");
    $unit_name = safe_post("unit_name", "");
    $topic = safe_post("topic", "");
    $link = safe_post("link", "");
    $description = safe_post("description", "");
    if ($subject === "" || $semester < 1 || $unit_no === "" || $unit_name === "" || $topic === "" || $link === "") {
      flash_set("bad", "Please fill all required fields.");
      go("staff_dashboard.php?h=7&v=1");
    }
    $filePath = null;
    if (!empty($_FILES["lecture_file"]["name"])) {
      $upDir = __DIR__ . "/uploads/eclasses";
      if (!is_dir($upDir)) { @mkdir($upDir, 0755, true); }
      $orig = basename($_FILES["lecture_file"]["name"]);
      $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
      $allowed = ["mp4","pdf","png","jpg","jpeg","webp"];
      if (!in_array($ext, $allowed, true)) {
        flash_set("bad", "Allowed files: MP4/PDF/JPG/PNG/WEBP.");
        go("staff_dashboard.php?h=7&v=1");
      }
      $fname = "lecture_".date("Ymd_His")."_".bin2hex(random_bytes(3)).".".$ext;
      $destAbs = $upDir . "/" . $fname;
      if (!@move_uploaded_file($_FILES["lecture_file"]["tmp_name"], $destAbs)) {
        flash_set("bad", "Upload failed.");
        go("staff_dashboard.php?h=7&v=1");
      }
      $filePath = "uploads/eclasses/".$fname;
    }
    if (db_has_table($conn, $T_ECLASS)) {
      $stmt = $conn->prepare("INSERT INTO `$T_ECLASS` (subject, semester, unit_no, unit_name, topic, video_link, description, file_path, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
      if ($stmt) {
        $stmt->bind_param("sissssss", $subject, $semester, $unit_no, $unit_name, $topic, $link, $description, $filePath);
        $stmt->execute();
        $stmt->close();
      }
    }
    flash_set("ok", "Video lecture uploaded.");
    go("staff_dashboard.php?h=7&v=2");
  }

  if ($action === "eclass_update") {
    $vid = (int)safe_post("vid", "0");
    $subject = safe_post("subject", "");
    $semester = (int)safe_post("semester", "0");
    $unit_no = safe_post("unit_no", "");
    $unit_name = safe_post("unit_name", "");
    $topic = safe_post("topic", "");
    $link = safe_post("link", "");
    $description = safe_post("description", "");
    if ($vid <= 0 || $subject === "" || $semester < 1 || $unit_no === "" || $unit_name === "" || $topic === "" || $link === "") {
      flash_set("bad", "Invalid update data.");
      go("staff_dashboard.php?h=7&v=2");
    }
    if (db_has_table($conn, $T_ECLASS)) {
      $stmt = $conn->prepare("UPDATE `$T_ECLASS` SET subject=?, semester=?, unit_no=?, unit_name=?, topic=?, video_link=?, description=? WHERE id=? LIMIT 1");
      if ($stmt) {
        $stmt->bind_param("sisssssi", $subject, $semester, $unit_no, $unit_name, $topic, $link, $description, $vid);
        $stmt->execute();
        $stmt->close();
      }
    }
    flash_set("ok", "Video lecture updated.");
    go("staff_dashboard.php?h=7&v=2");
  }

  if ($action === "eclass_delete") {
    $vid = (int)safe_post("vid", "0");
    if ($vid <= 0) {
      flash_set("bad", "Invalid video.");
      go("staff_dashboard.php?h=7&v=2");
    }
    $path = "";
    if (db_has_table($conn, $T_ECLASS)) {
      $stmt = $conn->prepare("SELECT file_path FROM `$T_ECLASS` WHERE id=? LIMIT 1");
      if ($stmt) {
        $stmt->bind_param("i", $vid);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $path = $row["file_path"] ?? "";
        $stmt->close();
      }
      $stmt = $conn->prepare("DELETE FROM `$T_ECLASS` WHERE id=? LIMIT 1");
      if ($stmt) { $stmt->bind_param("i", $vid); $stmt->execute(); $stmt->close(); }
    }
    if ($path !== "") {
      $abs = __DIR__ . "/" . ltrim($path, "/");
      if (is_file($abs)) { @unlink($abs); }
    }
    flash_set("ok", "Video lecture deleted.");
    go("staff_dashboard.php?h=7&v=2");
  }


// ============================
// B,4 Notification Actions
// ============================

// NOTICE/NEWS CREATE/UPDATE
if ($action === "notice_save") {
  $nid = (int)safe_post("notice_id","0");
  $title = safe_post("title","");
  $desc  = safe_post("description","");
  $ndate = safe_post("notice_date", date("Y-m-d"));
  $target= normalize_notice_target(safe_post("target","BOTH")); // WEBSITE / STUDENT_PORTAL / BOTH
  $kind  = strtoupper(safe_post("kind","NOTICE")); // NOTICE / NEWS
  $is_popup = (int)safe_post("is_popup","0");
  $is_scroll= (int)safe_post("is_scrolling","0");
  $is_active= (int)safe_post("is_active","1");

  if ($title==="") {
    flash_set("bad","Title is required.");
    go("staff_dashboard.php?h=4&v=1");
  }

  // file upload (PDF/Images) optional
  $attachmentPath = null;
  if (!empty($_FILES["attachment"]["name"])) {
    $upDir = __DIR__ . "/uploads/notices";
    if (!is_dir($upDir)) { @mkdir($upDir, 0755, true); }
    $orig = basename($_FILES["attachment"]["name"]);
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ["pdf","png","jpg","jpeg","webp"];
    if (!in_array($ext, $allowed, true)) {
      flash_set("bad","Attachment allowed: PDF/JPG/PNG/WEBP only.");
      go("staff_dashboard.php?h=4&v=1");
    }
    $fname = "notice_".date("Ymd_His")."_".bin2hex(random_bytes(3)).".".$ext;
    $destAbs = $upDir . "/" . $fname;
    if (@move_uploaded_file($_FILES["attachment"]["tmp_name"], $destAbs)) {
      $attachmentPath = "uploads/notices/".$fname;
    }
  }

  // Build SQL depending on DB columns (type/kind)
  $col1 = $NOTICE_COL_PRIMARY;
  $col2 = $NOTICE_COL_SECOND; // may be null

  if ($nid > 0) {
    // UPDATE
    if ($attachmentPath !== null) {
      if ($col2) {
        $sql = "UPDATE `$T_NOTICE` SET `$col1`=?, `$col2`=?, title=?, description=?, notice_date=?, target=?, is_popup=?, is_scrolling=?, is_active=?, attachment_path=? WHERE id=? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
          $stmt->bind_param("ssssssiiisi", $kind, $kind, $title, $desc, $ndate, $target, $is_popup, $is_scroll, $is_active, $attachmentPath, $nid);
          $ok = $stmt->execute();
          $err = $stmt->error;
          $stmt->close();
          if ($ok) {
            upsert_site_notice($conn, $title, $desc, $ndate, $target, $is_popup, $is_scroll, $is_active, ($u["id"] ?? 0));
            flash_set("ok","Notice/News updated.");
          } else {
            flash_set("bad","Save failed: ".$err);
          }
        } else {
          flash_set("bad","DB error: unable to prepare update.");
        }
      } else {
        $sql = "UPDATE `$T_NOTICE` SET `$col1`=?, title=?, description=?, notice_date=?, target=?, is_popup=?, is_scrolling=?, is_active=?, attachment_path=? WHERE id=? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
          $stmt->bind_param("sssssiiisi", $kind, $title, $desc, $ndate, $target, $is_popup, $is_scroll, $is_active, $attachmentPath, $nid);
          $ok = $stmt->execute();
          $err = $stmt->error;
          $stmt->close();
          if ($ok) {
            upsert_site_notice($conn, $title, $desc, $ndate, $target, $is_popup, $is_scroll, $is_active, ($u["id"] ?? 0));
            flash_set("ok","Notice/News updated.");
          } else {
            flash_set("bad","Save failed: ".$err);
          }
        } else {
          flash_set("bad","DB error: unable to prepare update.");
        }
      }
    } else {
      if ($col2) {
        $sql = "UPDATE `$T_NOTICE` SET `$col1`=?, `$col2`=?, title=?, description=?, notice_date=?, target=?, is_popup=?, is_scrolling=?, is_active=? WHERE id=? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
          $stmt->bind_param("ssssssiiii", $kind, $kind, $title, $desc, $ndate, $target, $is_popup, $is_scroll, $is_active, $nid);
          $ok = $stmt->execute();
          $err = $stmt->error;
          $stmt->close();
          if ($ok) {
            upsert_site_notice($conn, $title, $desc, $ndate, $target, $is_popup, $is_scroll, $is_active, ($u["id"] ?? 0));
            flash_set("ok","Notice/News updated.");
          } else {
            flash_set("bad","Save failed: ".$err);
          }
        } else {
          flash_set("bad","DB error: unable to prepare update.");
        }
      } else {
        $sql = "UPDATE `$T_NOTICE` SET `$col1`=?, title=?, description=?, notice_date=?, target=?, is_popup=?, is_scrolling=?, is_active=? WHERE id=? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
          $stmt->bind_param("sssssiiii", $kind, $title, $desc, $ndate, $target, $is_popup, $is_scroll, $is_active, $nid);
          $ok = $stmt->execute();
          $err = $stmt->error;
          $stmt->close();
          if ($ok) {
            upsert_site_notice($conn, $title, $desc, $ndate, $target, $is_popup, $is_scroll, $is_active, ($u["id"] ?? 0));
            flash_set("ok","Notice/News updated.");
          } else {
            flash_set("bad","Save failed: ".$err);
          }
        } else {
          flash_set("bad","DB error: unable to prepare update.");
        }
      }
    }
  } else {
    // INSERT
    // Build dynamic insert based on available columns in notice_news table
    $cols = [];
    $vals = [];
    $types = "";
    $bind = [];

    // kind/type columns
    $cols[] = "`$col1`"; $vals[] = "?"; $types .= "s"; $bind[] = $kind;
    if ($col2) { $cols[] = "`$col2`"; $vals[] = "?"; $types .= "s"; $bind[] = $kind; }

    // base columns
    $cols = array_merge($cols, ["title","description","notice_date","target","is_popup","is_scrolling","is_active"]);
    $vals = array_merge($vals, ["?","?","?","?","?","?","?"]);
    $types .= "ssssiii";
    $bind = array_merge($bind, [$title,$desc,$ndate,$target,$is_popup,$is_scroll,$is_active]);

    // attachment columns
    if (db_has_column($conn, $T_NOTICE, "attachment_path")) {
      $cols[] = "attachment_path"; $vals[] = "?"; $types .= "s"; $bind[] = (string)$attachmentPath;
    }
    if (db_has_column($conn, $T_NOTICE, "attachment_type")) {
      $cols[] = "attachment_type"; $vals[] = "?"; $types .= "s"; 
      $bind[] = $attachmentPath ? strtoupper(pathinfo($attachmentPath, PATHINFO_EXTENSION)) : null;
    }

    // created_by columns (support both naming styles)
    $createdBy = (int)($u["id"] ?? 0);
    if (db_has_column($conn, $T_NOTICE, "created_by_role")) {
      $cols[] = "created_by_role"; $vals[] = "?"; $types .= "s"; $bind[] = "STAFF";
    }
    if (db_has_column($conn, $T_NOTICE, "created_by_id")) {
      $cols[] = "created_by_id"; $vals[] = "?"; $types .= "i"; $bind[] = $createdBy;
    }
    if (db_has_column($conn, $T_NOTICE, "created_by")) {
      $cols[] = "created_by"; $vals[] = "?"; $types .= "i"; $bind[] = $createdBy;
    }

    // timestamps
    $nowSql = "NOW()";
    if (db_has_column($conn, $T_NOTICE, "created_at")) { $cols[] = "created_at"; $vals[] = $nowSql; }
    if (db_has_column($conn, $T_NOTICE, "updated_at")) { $cols[] = "updated_at"; $vals[] = $nowSql; }

    $sql = "INSERT INTO `$T_NOTICE` (".implode(",",$cols).") VALUES (".implode(",",$vals).")";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
      $stmt->bind_param($types, ...$bind);
      $ok = $stmt->execute();
      $err = $stmt->error;
      $stmt->close();
      if ($ok) {
        upsert_site_notice($conn, $title, $desc, $ndate, $target, $is_popup, $is_scroll, $is_active, ($u["id"] ?? 0));
        flash_set("ok","Notice/News created.");
      } else {
        flash_set("bad","Save failed: ".$err);
      }
    } else {
      flash_set("bad","DB error: unable to create notice/news.");
    }
  }
  go("staff_dashboard.php?h=4&v=1");
}

if ($action === "notice_delete") {
  $nid = (int)safe_post("notice_id","0");
  if ($nid>0) {
    $title = "";
    $ndate = "";
    $stmt = $conn->prepare("SELECT title, notice_date FROM `$T_NOTICE` WHERE id=? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("i", $nid);
      $stmt->execute();
      $res = $stmt->get_result();
      $row = $res ? $res->fetch_assoc() : null;
      $title = $row["title"] ?? "";
      $ndate = $row["notice_date"] ?? "";
      $stmt->close();
    }
    $stmt = $conn->prepare("DELETE FROM `$T_NOTICE` WHERE id=? LIMIT 1");
    if ($stmt) { $stmt->bind_param("i",$nid); $stmt->execute(); $stmt->close(); }
    if ($title !== "" && $ndate !== "" && db_has_table($conn, "site_notices")) {
      $stmt = $conn->prepare("DELETE FROM site_notices WHERE title=? AND publish_from=?");
      if ($stmt) {
        $stmt->bind_param("ss", $title, $ndate);
        $stmt->execute();
        $stmt->close();
      }
    }
    flash_set("ok","Notice/News deleted.");
  }
  go("staff_dashboard.php?h=4&v=1");
}

// SLIDER UPLOAD
if ($action === "slider_upload") {
  if (empty($_FILES["slider_image"]["name"])) {
    flash_set("bad","Please choose an image.");
    go("staff_dashboard.php?h=4&v=2");
  }
  $upDir = __DIR__ . "/uploads/slider";
  if (!is_dir($upDir)) { @mkdir($upDir, 0755, true); }
  $orig = basename($_FILES["slider_image"]["name"]);
  $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
  $allowed = ["png","jpg","jpeg","webp"];
  if (!in_array($ext, $allowed, true)) {
    flash_set("bad","Slider image allowed: JPG/PNG/WEBP only.");
    go("staff_dashboard.php?h=4&v=2");
  }
  $fname = "slider_".date("Ymd_His")."_".bin2hex(random_bytes(3)).".".$ext;
  $destAbs = $upDir . "/" . $fname;
  if (!@move_uploaded_file($_FILES["slider_image"]["tmp_name"], $destAbs)) {
    flash_set("bad","Upload failed.");
    go("staff_dashboard.php?h=4&v=2");
  }
  $path = "uploads/slider/".$fname;
  $sort = (int)safe_post("sort_order","0");
  $active = (int)safe_post("is_active","1");
  $stmt = $conn->prepare("INSERT INTO `$T_SLIDER` (image_path,sort_order,is_active,created_by,created_at) VALUES (?,?,?,?,NOW())");
  if ($stmt) {
    $createdBy = (int)($u["id"] ?? 0);
    $stmt->bind_param("siii",$path,$sort,$active,$createdBy);
    $stmt->execute();
    $stmt->close();
    flash_set("ok","Slider image added.");
  } else {
    flash_set("bad","DB error: slider insert failed.");
  }
  go("staff_dashboard.php?h=4&v=2");
}

if ($action === "slider_delete") {
  $sid = (int)safe_post("slider_id","0");
  if ($sid>0) {
    $stmt = $conn->prepare("DELETE FROM `$T_SLIDER` WHERE id=? LIMIT 1");
    if ($stmt) { $stmt->bind_param("i",$sid); $stmt->execute(); $stmt->close(); }
    flash_set("ok","Slider image removed.");
  }
  go("staff_dashboard.php?h=4&v=2");
}

// DIRECT MESSAGE SEND
if ($action === "msg_send") {
  $studentUserId = (int)safe_post("student_user_id","0");
  $message = safe_post("message","");
  $expires = safe_post("expires_at","");
  $priority = (int)safe_post("priority","10");
  if ($studentUserId<=0 || $message==="") {
    flash_set("bad","Student User ID and Message are required.");
    go("staff_dashboard.php?h=4&v=3");
  }
  if ($expires==="") {
    // default 7 days
    $expires = date("Y-m-d H:i:s", time()+7*24*3600);
  } else {
    // normalize from datetime-local
    $expires = str_replace("T"," ",$expires);
    if (strlen($expires)===16) $expires .= ":00";
  }
  // validate student exists
  $ok = false;
  $st = $conn->prepare("SELECT id FROM users WHERE id=? LIMIT 1");
  if ($st) {
    $st->bind_param("i",$studentUserId);
    $st->execute();
    $res = $st->get_result();
    $ok = ($res && $res->fetch_assoc()) ? true : false;
    $st->close();
  }
  if (!$ok) {
    flash_set("bad","Student user not found (users.id).");
    go("staff_dashboard.php?h=4&v=3");
  }

  $priorityEnum = "NORMAL";
  if ($priority <= 3) $priorityEnum = "URGENT";
  elseif ($priority <= 6) $priorityEnum = "HIGH";

  $studentAdmissionId = null;
  if (db_has_column($conn, $T_ENQ, "student_user_id")) {
    $st = $conn->prepare("SELECT id FROM `$T_ENQ` WHERE student_user_id=? LIMIT 1");
    if ($st) {
      $st->bind_param("i", $studentUserId);
      $st->execute();
      $res = $st->get_result();
      $row = $res ? $res->fetch_assoc() : null;
      $studentAdmissionId = $row ? (int)$row["id"] : null;
      $st->close();
    }
  }

  $title = "Staff Message";
  $cols = ["student_user_id","message","expires_at","is_active"];
  $vals = ["?","?","?","1"];
  $types = "iss";
  $bind = [$studentUserId, $message, $expires];

  if (db_has_column($conn, $T_MSG, "title")) {
    $cols[] = "title";
    $vals[] = "?";
    $types .= "s";
    $bind[] = $title;
  }
  if (db_has_column($conn, $T_MSG, "priority")) {
    $cols[] = "priority";
    $vals[] = "?";
    $types .= "s";
    $bind[] = $priorityEnum;
  }
  if (db_has_column($conn, $T_MSG, "student_admission_id") && $studentAdmissionId) {
    $cols[] = "student_admission_id";
    $vals[] = "?";
    $types .= "i";
    $bind[] = $studentAdmissionId;
  }
  if (db_has_column($conn, $T_MSG, "created_by_role")) {
    $cols[] = "created_by_role";
    $vals[] = "?";
    $types .= "s";
    $bind[] = "STAFF";
  }
  if (db_has_column($conn, $T_MSG, "created_by_id")) {
    $cols[] = "created_by_id";
    $vals[] = "?";
    $types .= "i";
    $bind[] = (int)($u["id"] ?? 0);
  }
  if (db_has_column($conn, $T_MSG, "created_at")) {
    $cols[] = "created_at";
    $vals[] = "NOW()";
  }

  $sql = "INSERT INTO `$T_MSG` (".implode(",", $cols).") VALUES (".implode(",", $vals).")";
  $stmt = $conn->prepare($sql);
  if ($stmt) {
    $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $stmt->close();
    flash_set("ok","Direct message sent.");
  } else {
    flash_set("bad","DB error: message insert failed.");
  }
  go("staff_dashboard.php?h=4&v=3");
}

if ($action === "msg_deactivate") {
  $mid = (int)safe_post("msg_id","0");
  if ($mid>0) {
    $stmt = $conn->prepare("UPDATE `$T_MSG` SET is_active=0 WHERE id=? LIMIT 1");
    if ($stmt) { $stmt->bind_param("i",$mid); $stmt->execute(); $stmt->close(); }
    flash_set("ok","Message deactivated.");
  }
  go("staff_dashboard.php?h=4&v=3");
}



// B,2,4 / B,2,5: delete admission
if($action==="admission_delete"){
  $id=(int)($_POST["id"]??0);
  if($id>0){
    $stmt=$conn->prepare("DELETE FROM `$T_ENQ` WHERE id=? LIMIT 1");
    if($stmt){ $stmt->bind_param("i",$id); $stmt->execute(); $stmt->close(); }
    flash_set("ok","Admission deleted.");
  } else {
    flash_set("bad","Invalid admission id.");
  }
  go("staff_dashboard.php?h=2&v=5");
}

// B,2,5: Reports actions (export CSV / bulk documents ZIP)
if($action==="report_action"){
  $do=$_POST["do"]??"";
  $ids=$_POST["ids"]??[];
  if(!is_array($ids) || count($ids)==0){ flash_set("bad","Please select at least 1 record."); go("staff_dashboard.php?h=2&v=5"); }
  $ids=array_map("intval",$ids);
  $ids=array_values(array_filter($ids,function($x){return $x>0;}));
  if(!$ids){ flash_set("bad","Invalid selection."); go("staff_dashboard.php?h=2&v=5"); }

  $in = implode(",", array_fill(0, count($ids), "?"));
  $types = str_repeat("i", count($ids));
  $sql = "SELECT * FROM `$T_ENQ` WHERE id IN ($in)";
  $stmt=$conn->prepare($sql);
  $rows=[];
  if($stmt){
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res=$stmt->get_result();
    $rows=$res?$res->fetch_all(MYSQLI_ASSOC):[];
    $stmt->close();
  }

  if($do==="export_csv"){
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=ddvm_admissions_export.csv");
    $out=fopen("php://output","w");
    if($rows){
      fputcsv($out, array_keys($rows[0]));
      foreach($rows as $r){ fputcsv($out, $r); }
    }
    fclose($out);
    exit;
  }

  if($do==="bulk_zip"){
    if(!class_exists("ZipArchive")){ flash_set("bad","ZIP not supported on server (ZipArchive missing)."); go("staff_dashboard.php?h=2&v=5"); }
    $zipPath = sys_get_temp_dir()."/ddvm_docs_".time().".zip";
    $zip=new ZipArchive();
    if($zip->open($zipPath, ZipArchive::CREATE)!==TRUE){ flash_set("bad","Unable to create ZIP."); go("staff_dashboard.php?h=2&v=5"); }
    $baseDir = realpath(__DIR__);
    foreach($rows as $r){
      $reg = $r["reg_no"] ?? ("ID".$r["id"]);
      $files = admission_doc_candidates($r, $baseDir);
      foreach ($files as $real) {
        $zip->addFile($real, $reg."/".basename($real));
      }
    }
    $zip->close();
    header("Content-Type: application/zip");
    header("Content-Disposition: attachment; filename=ddvm_documents.zip");
    header("Content-Length: ".filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
  }

  if($do==="bulk_forms_zip"){
    if(!class_exists("ZipArchive")){ flash_set("bad","ZIP not supported on server (ZipArchive missing)."); go("staff_dashboard.php?h=2&v=5"); }
    $zipPath = sys_get_temp_dir()."/ddvm_forms_".time().".zip";
    $zip=new ZipArchive();
    if($zip->open($zipPath, ZipArchive::CREATE)!==TRUE){ flash_set("bad","Unable to create ZIP."); go("staff_dashboard.php?h=2&v=5"); }
    $baseDir = realpath(__DIR__);
    $tmpFiles = [];
    foreach($rows as $r){
      $reg = $r["reg_no"] ?? ("ID".$r["id"]);
      $html = admission_html_summary($r);
      $tmp = tempnam(sys_get_temp_dir(), "adm_");
      file_put_contents($tmp, $html);
      $zip->addFile($tmp, $reg."/admission_form.html");
      $tmpFiles[] = $tmp;
      $files = admission_doc_candidates($r, $baseDir);
      foreach ($files as $real) {
        $zip->addFile($real, $reg."/".basename($real));
      }
    }
    $zip->close();
    header("Content-Type: application/zip");
    header("Content-Disposition: attachment; filename=ddvm_admission_forms.zip");
    header("Content-Length: ".filesize($zipPath));
    readfile($zipPath);
    foreach ($tmpFiles as $tmp) { @unlink($tmp); }
    @unlink($zipPath);
    exit;
  }

  flash_set("bad","Please select valid report action.");
  go("staff_dashboard.php?h=2&v=5");
}

flash_set("bad", "Unknown action.");
  go("staff_dashboard.php?h=".$H."&v=".$V);
}

// ============================
// DATA LOADERS
// ============================
$view_id = (int)safe_get("view", "0");
$view_row = null;
$edit_id = (int)safe_get("edit", "0");
$edit_row = null;

if ($view_id > 0) {
  $stmt = $conn->prepare("SELECT * FROM `$T_ENQ` WHERE id=? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param("i", $view_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $view_row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
  }
}

if ($edit_id > 0) {
  $stmt = $conn->prepare("SELECT * FROM `$T_ENQ` WHERE id=? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $edit_row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
  }
}

// Fetch tables for Operations
$new_rows = $verified_rows = $students_rows = [];
if ($H === 2) {
  // New: status empty / PENDING
  $qNew = "SELECT * FROM `$T_ENQ` WHERE (status='' OR status IS NULL OR UPPER(status)='PENDING') ORDER BY id DESC LIMIT 200";
  $r = $conn->query($qNew);
  if ($r) { $new_rows = $r->fetch_all(MYSQLI_ASSOC); $r->free(); }

  // Verified tab shows ACCEPTED (waiting for verify)
  $qVer = "SELECT * FROM `$T_ENQ` WHERE UPPER(status)='ACCEPTED' ORDER BY id DESC LIMIT 200";
  $r = $conn->query($qVer);
  if ($r) { $verified_rows = $r->fetch_all(MYSQLI_ASSOC); $r->free(); }

  // Total Students: VERIFIED
  $qStu = "SELECT * FROM `$T_ENQ` WHERE UPPER(status)='VERIFIED' ORDER BY id DESC LIMIT 500";
  $r = $conn->query($qStu);
  if ($r) { $students_rows = $r->fetch_all(MYSQLI_ASSOC); $r->free(); }
}


// Fetch Notification data (B,4)
$notice_rows = [];
$slider_rows = [];
$msg_rows = [];
if ($H === 4) {
  // Notices/News list
  $r = $conn->query("SELECT * FROM `$T_NOTICE` ORDER BY id DESC LIMIT 300");
  if ($r) { $notice_rows = $r->fetch_all(MYSQLI_ASSOC); $r->free(); }

  // Slider list
  $r = $conn->query("SELECT * FROM `$T_SLIDER` ORDER BY sort_order ASC, id DESC LIMIT 200");
  if ($r) { $slider_rows = $r->fetch_all(MYSQLI_ASSOC); $r->free(); }

  // Direct messages list
  $r = $conn->query("SELECT m.*, u.full_name as student_name, u.email as student_email
                     FROM `$T_MSG` m
                     LEFT JOIN users u ON u.id = m.student_user_id
                     ORDER BY m.id DESC LIMIT 300");
  if ($r) { $msg_rows = $r->fetch_all(MYSQLI_ASSOC); $r->free(); }
}

// Column pickers (safe display)
function pick($row, $keys){
  foreach ($keys as $k) {
    if (isset($row[$k]) && trim((string)$row[$k]) !== "") return (string)$row[$k];
  }
  return "";
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DDVM College - Staff Portal</title>
<style>
  :root{
    --bg:#f3f7ff;
    --card:#ffffff;
    --ink:#0f172a;
    --mut:#64748b;
    --line: rgba(15,23,42,.10);
    --blue1:#071a35;
    --blue2:#0b2a5b;
    --pill:#0b2a5b;
  }
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:var(--bg);color:var(--ink)}
  .topbar{
    background:linear-gradient(90deg,var(--blue1),var(--blue2));
    color:#fff;padding:14px 18px;
    display:flex;align-items:center;justify-content:space-between;
    box-shadow:0 10px 24px rgba(0,0,0,.15);
  }
  .brand{display:flex;gap:12px;align-items:center}
  .logo{
    width:44px;height:44px;border-radius:999px;background:#fff;
    display:flex;align-items:center;justify-content:center;overflow:hidden;
    border:2px solid rgba(255,255,255,.45);
  }
  .logo img{width:100%;height:100%;object-fit:cover}
  .bt{font-weight:950;font-size:20px;line-height:1.1}
  .bs{opacity:.86;font-size:13px;margin-top:2px;font-weight:800}
  .userbox{display:flex;align-items:center;gap:16px}
  .uname{font-weight:950}
  .urole{opacity:.9;font-weight:900;font-size:12px;margin-top:2px}
  .logout{
    background:rgba(255,255,255,.16);
    border:1px solid rgba(255,255,255,.22);
    color:#fff;border-radius:999px;padding:10px 14px;
    font-weight:950;cursor:pointer;text-decoration:none;
  }
  .logout:hover{filter:brightness(1.07)}
  .wrap{max-width:1250px;margin:18px auto;padding:0 14px}

  /* Horizontal Menu */
  .hmenu{display:flex;gap:12px;flex-wrap:wrap;align-items:center;padding:12px 0}
  .hitem{
    text-decoration:none;
    font-weight:950;
    padding:10px 14px;border-radius:999px;
    border:1px solid rgba(15,23,42,.10);
    background:#fff;color:var(--blue2);
  }
  .hitem.active{
    background:linear-gradient(90deg,var(--blue1),var(--blue2));
    color:#fff;border-color:transparent;
    box-shadow:0 10px 22px rgba(11,42,91,.16);
  }

  .grid{display:grid;grid-template-columns:260px 1fr;gap:16px;align-items:start}
  @media(max-width:980px){.grid{grid-template-columns:1fr}}
  .card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:18px;
    box-shadow:0 12px 28px rgba(15,23,42,.08);
    padding:14px;
  }
  .sideTitle{font-weight:950;font-size:14px;color:var(--mut);margin:0 0 10px}
  .vmenu{display:flex;flex-direction:column;gap:8px}
  .vitem{
    display:flex;justify-content:space-between;align-items:center;
    text-decoration:none;color:var(--ink);
    padding:10px 12px;border-radius:14px;border:1px solid rgba(15,23,42,.08);
    font-weight:950;background:#fff;
  }
  .vitem.active{background:#f1f5ff;border-color:rgba(11,42,91,.25)}
  .badge{font-size:12px;font-weight:950;color:#0b2a5b;background:#e9efff;border:1px solid rgba(11,42,91,.18);padding:2px 8px;border-radius:999px}
  .title{font-weight:950;font-size:18px;margin:0}
  .sub{color:var(--mut);font-size:12px;font-weight:800;margin:6px 0 0}

  .flash{margin:12px 0;border-radius:14px;padding:12px 14px;font-weight:950}
  .flash.ok{background:#e9f9ee;border:1px solid #bfe8cb;color:#0f5132}
  .flash.bad{background:#ffecec;border:1px solid #ffc1c1;color:#7d1d1d}

  table{width:100%;border-collapse:separate;border-spacing:0 10px}
  th{font-size:12px;color:var(--mut);text-align:left;padding:0 10px}
  td{background:#fff;border:1px solid rgba(15,23,42,.08);padding:10px;border-left:none;border-right:none}
  tr td:first-child{border-left:1px solid rgba(15,23,42,.08);border-top-left-radius:14px;border-bottom-left-radius:14px}
  tr td:last-child{border-right:1px solid rgba(15,23,42,.08);border-top-right-radius:14px;border-bottom-right-radius:14px}
  .btn{
    display:inline-block;text-decoration:none;cursor:pointer;
    padding:8px 10px;border-radius:12px;font-weight:950;font-size:12px;
    border:1px solid rgba(15,23,42,.12);
    background:#fff;color:var(--blue2);
  }
  .btn:hover{filter:brightness(0.98)}
  .btn.primary{background:linear-gradient(90deg,var(--blue1),var(--blue2));color:#fff;border-color:transparent}
  .btn.danger{background:#fff;color:#7d1d1d;border-color:#ffc1c1}
  .btn.ok{background:#fff;color:#0f5132;border-color:#bfe8cb}
  .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .inp{padding:10px 12px;border-radius:14px;border:1px solid rgba(15,23,42,.12);outline:none}
  .inp:focus{box-shadow:0 0 0 4px rgba(11,42,91,.18);border-color:rgba(11,42,91,.40)}
  .mut{color:var(--mut);font-weight:800;font-size:12px}
  .kv{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  @media(max-width:980px){.kv{grid-template-columns:1fr}}
  .k{padding:10px 12px;border:1px solid rgba(15,23,42,.08);border-radius:14px;background:#fff}
  .k b{display:block;font-size:12px;color:var(--mut);margin-bottom:4px}
  .k span{font-weight:950;color:var(--ink);word-break:break-word}
</style>
</head>
<body>

<!-- TOP BAR (COMMON for all users) -->
<div class="topbar">
  <div class="brand">
    <div class="logo"><img src="logo.png" onerror="this.style.display='none'"></div>
    <div>
      <div class="bt">DDVM College</div>
      <div class="bs">Staff Portal</div>
    </div>
  </div>
  <div class="userbox">
    <div>
      <div class="uname"><?=h($name)?></div>
      <div class="urole">Role: <?=h($role)?></div>
    </div>
    <a class="logout" href="logout.php">Logout</a>
  </div>
</div>

<div class="wrap">

  <!-- HORIZONTAL MENU (COMMON style) -->
  <div class="hmenu">
    <a class="hitem <?=($H===1?'active':'')?>" href="staff_dashboard.php?h=1&v=1">1. Dashboard</a>
    <a class="hitem <?=($H===2?'active':'')?>" href="staff_dashboard.php?h=2&v=1">2. Operations</a>
    <a class="hitem <?=($H===3?'active':'')?>" href="staff_dashboard.php?h=3&v=1">3. Fees & Accounting</a>
    <a class="hitem <?=($H===4?'active':'')?>" href="staff_dashboard.php?h=4&v=1">4. Notification</a>
    <a class="hitem <?=($H===5?'active':'')?>" href="staff_dashboard.php?h=5&v=1">5. Enquiries</a>
    <a class="hitem <?=($H===6?'active':'')?>" href="staff_dashboard.php?h=6&v=1">6. E-Digital Library</a>
    <a class="hitem <?=($H===7?'active':'')?>" href="staff_dashboard.php?h=7&v=1">7. E-Classes</a>
  </div>

  <?php if($flash): ?>
    <div class="flash <?=$flash['type']=='ok'?'ok':'bad'?>"><?=h($flash['msg'])?></div>
  <?php endif; ?>

  <div class="grid">
    <!-- LEFT SIDEBAR -->
    <div class="card">
      <p class="sideTitle">Vertical Menu</p>

      <div class="vmenu">
        <?php if($H===1): ?>
          <a class="vitem <?=($V===1?'active':'')?>" href="staff_dashboard.php?h=1&v=1"><span>1. Overview</span></a>
          <a class="vitem <?=($V===2?'active':'')?>" href="staff_dashboard.php?h=1&v=2"><span>2. Quick Links</span></a>
        <?php elseif($H===2): ?>
          <a class="vitem <?=($V===1?'active':'')?>" href="staff_dashboard.php?h=2&v=1"><span>1. New Admission Requests</span><span class="badge"><?=count($new_rows)?></span></a>
          <a class="vitem <?=($V===2?'active':'')?>" href="staff_dashboard.php?h=2&v=2"><span>2. Accepted (Pending Verify)</span><span class="badge"><?=count($verified_rows)?></span></a>
          <a class="vitem <?=($V===3?'active':'')?>" href="staff_dashboard.php?h=2&v=3"><span>3. Total Students</span><span class="badge"><?=count($students_rows)?></span></a>
          <a class="vitem <?=($V===4?'active':'')?>" href="staff_dashboard.php?h=2&v=4"><span>4. All Admissions (Search)</span></a>
          <a class="vitem <?=($V===5?'active':'')?>" href="staff_dashboard.php?h=2&v=5"><span>5. Reports (Export/Bulk)</span></a>
        <?php elseif($H===3): ?>
          <a class="vitem <?=($V===1?'active':'')?>" href="staff_dashboard.php?h=3&v=1"><span>1. Fees Dashboard</span></a>
          <a class="vitem <?=($V===2?'active':'')?>" href="staff_dashboard.php?h=3&v=2"><span>2. Fee Transactions</span></a>
          <a class="vitem <?=($V===3?'active':'')?>" href="staff_dashboard.php?h=3&v=3"><span>3. Payment History</span></a>
        <?php elseif($H===4): ?>
          <a class="vitem <?=($V===1?'active':'')?>" href="staff_dashboard.php?h=4&v=1"><span>1. Notice & News</span></a>
          <a class="vitem <?=($V===2?'active':'')?>" href="staff_dashboard.php?h=4&v=2"><span>2. Slider Images</span></a>
          <a class="vitem <?=($V===3?'active':'')?>" href="staff_dashboard.php?h=4&v=3"><span>3. Direct Message</span></a>
        <?php elseif($H===5): ?>
          <a class="vitem <?=($V===1?'active':'')?>" href="staff_dashboard.php?h=5&v=1"><span>1. Enquiries</span></a>
        <?php elseif($H===6): ?>
          <a class="vitem <?=($V===1?'active':'')?>" href="staff_dashboard.php?h=6&v=1"><span>1. Upload Books</span></a>
          <a class="vitem <?=($V===2?'active':'')?>" href="staff_dashboard.php?h=6&v=2"><span>2. Book List</span></a>
        <?php elseif($H===7): ?>
          <a class="vitem <?=($V===1?'active':'')?>" href="staff_dashboard.php?h=7&v=1"><span>1. Upload Video Lecture</span></a>
          <a class="vitem <?=($V===2?'active':'')?>" href="staff_dashboard.php?h=7&v=2"><span>2. Online Video Lectures</span></a>
        <?php endif; ?>
      </div>

      <div style="margin-top:12px" class="mut">
        Tip: A,B,C,D roles me theme same rahega. Sirf menu/module change hoga.
      </div>
    </div>

    <!-- RIGHT CONTENT -->
    <div class="card">
      <?php if($H===1 && $V===1): ?>
        <h2 class="title">Staff Dashboard</h2>
        <p class="sub">Welcome! Yahan se aap Dashboard summary, Operations, Fees & Accounting, Notifications, Enquiries handle karoge.</p>

        <div class="row" style="margin-top:12px">
          <a class="btn primary" href="staff_dashboard.php?h=2&v=1">Go to Operations</a>
          <a class="btn" href="staff_dashboard.php?h=3&v=1">Go to Fees</a>
          <a class="btn" href="staff_dashboard.php?h=4&v=1">Go to Notification</a>
          <a class="btn" href="staff_dashboard.php?h=5&v=1">Go to Enquiries</a>
        </div>

      <?php elseif($H===2): ?>
        <?php if($V===1): ?>
          <h2 class="title">Operations → New Admission Requests</h2>
          <p class="sub">Yahan aap student admission form ko View karke Accept / Reject kar sakte ho.</p>

          <?php if($view_row): ?>
            <div style="margin:14px 0;padding:12px;border:1px solid rgba(11,42,91,.18);border-radius:16px;background:#f8fbff">
              <div class="row" style="justify-content:space-between">
                <div style="font-weight:950">View Form (Admission ID: <?=h($view_id)?>)</div>
                <a class="btn" href="staff_dashboard.php?h=2&v=1">Close</a>
              </div>

              <div class="kv" style="margin-top:10px">
                <?php foreach($view_row as $k=>$v): ?>
                  <div class="k"><b><?=h($k)?></b><span><?=h((string)$v)?></span></div>
                <?php endforeach; ?>
                
              </div>
            </div>
          <?php endif; ?>

          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Reg No</th>
                <th>Name</th>
                <th>Mobile</th>
                <th>Course</th>
                <th>Session</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($new_rows as $r): ?>
                <tr>
                  <td><?=h($r["id"] ?? "")?></td>
                  <td><?=h(pick($r, ["reg_no","registration_no","regno"]))?></td>
                  <td><?=h(pick($r, ["name","student_name","full_name"]))?></td>
                  <td><?=h(pick($r, ["mobile","phone","mobile_no"]))?></td>
                  <td><?=h(pick($r, ["course","course_apply","program"]))?></td>
                  <td><?=h(pick($r, ["session","academic_session"]))?></td>
                  <td><?=h($r["status"] ?? "PENDING")?></td>
                  <td>
                    <div class="row">
                      <a class="btn" href="staff_dashboard.php?h=2&v=1&view=<?=h($r['id'])?>">View Form</a>

                      <form method="post" style="display:inline-flex;gap:8px;flex-wrap:wrap;align-items:center">
                        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                        <input type="hidden" name="action" value="accept">
                        <input type="hidden" name="id" value="<?=h($r['id'])?>">
                        <input class="inp" name="total_fees" placeholder="Total Fees" style="width:120px" required>
                        <input class="inp" name="remark" placeholder="Remark" style="width:160px" required>
                        <button class="btn ok" type="submit">Accept</button>
                      </form>

                      <form method="post" style="display:inline-flex;gap:8px;flex-wrap:wrap;align-items:center">
                        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="id" value="<?=h($r['id'])?>">
                        <input class="inp" name="remark" placeholder="Reject reason" style="width:160px" required>
                        <button class="btn danger" type="submit">Reject</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if(count($new_rows)===0): ?>
                <tr><td colspan="8" style="background:transparent;border:none;padding:0">
                  <div style="padding:14px;border:1px dashed rgba(15,23,42,.20);border-radius:16px;background:#fff">
                    No new admission requests.
                  </div>
                </td></tr>
              <?php endif; ?>
            </tbody>
          </table>

        <?php elseif($V===2): ?>
          <h2 class="title">Operations → Verified (Registered)</h2>
          <p class="sub">Yahan ACCEPTED admissions aayenge. Verify karne par Student login auto create/activate hoga.</p>

          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Reg No</th>
                <th>Name</th>
                <th>Course</th>
                <th>Total Fees</th>
                <th>Staff Remark</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($verified_rows as $r): ?>
                <tr>
                  <td><?=h($r["id"] ?? "")?></td>
                  <td><?=h(pick($r, ["reg_no","registration_no","regno"]))?></td>
                  <td><?=h(pick($r, ["name","student_name","full_name"]))?></td>
                  <td><?=h(pick($r, ["course","course_apply","program"]))?></td>
                  <td>
                    <form method="post" style="display:inline-flex;gap:8px;flex-wrap:wrap;align-items:center">
                      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                      <input type="hidden" name="action" value="accept_update">
                      <input type="hidden" name="id" value="<?=h($r['id'])?>">
                      <input class="inp" name="total_fees" placeholder="Total Fees" style="width:120px" value="<?=h($r["total_fees"] ?? "")?>" required>
                  </td>
                  <td>
                      <input class="inp" name="remark" placeholder="Remark" style="width:160px" value="<?=h(pick($r, ["staff_remark2","staff_remark","remark"]))?>" required>
                      <button class="btn ok" type="submit">Save</button>
                    </form>
                  </td>
                  <td>
                    <div class="row">
                      <a class="btn" href="staff_dashboard.php?h=2&v=2&view=<?=h($r['id'])?>">View Form</a>

                      <form method="post" style="display:inline">
                        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                        <input type="hidden" name="action" value="verify">
                        <input type="hidden" name="id" value="<?=h($r['id'])?>">
                        <button class="btn primary" type="submit">Verify</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if(count($verified_rows)===0): ?>
                <tr><td colspan="7" style="background:transparent;border:none;padding:0">
                  <div style="padding:14px;border:1px dashed rgba(15,23,42,.20);border-radius:16px;background:#fff">
                    No accepted admissions pending verification.
                  </div>
                </td></tr>
              <?php endif; ?>
            </tbody>
          </table>

        <?php
 elseif($V===3): ?>
          <h2>Operations → Total Students</h2>
          <div class="muted">Yahan VERIFIED students show honge.</div>
          <?php
            $sql="SELECT id, reg_no, student_name, mobile, course, session, total_fees FROM `$T_ADM` WHERE status IN ('VERIFIED','REGISTERED') ORDER BY id DESC LIMIT 200";
            $r=$conn->query($sql);
            $rows=$r?$r->fetch_all(MYSQLI_ASSOC):[];
          ?>
          <table class="tbl">
            <tr><th>ID</th><th>Reg No</th><th>Name</th><th>Mobile</th><th>Course</th><th>Session</th><th>Total Fees</th><th>Action</th></tr>
            <?php if(!$rows): ?><tr><td colspan="8" class="muted">No students found.</td></tr><?php endif; ?>
            <?php foreach($rows as $rw): ?>
              <tr>
                <td><?=h($rw["id"])?></td>
                <td><?=h($rw["reg_no"])?></td>
                <td><?=h($rw["student_name"])?></td>
                <td><?=h($rw["mobile"])?></td>
                <td><?=h($rw["course"])?></td>
                <td><?=h($rw["session"])?></td>
                <td><?=h($rw["total_fees"])?></td>
                <td><a class="btn tiny" href="staff_dashboard.php?h=2&v=4&q=<?=urlencode($rw["reg_no"])?>">Open</a></td>
              </tr>
            <?php endforeach; ?>
          </table>

        <?php elseif($V===4): ?>
          <h2>Operations → All Admissions (Search)</h2>
          <form class="filters" method="get" action="staff_dashboard.php">
            <input type="hidden" name="h" value="2"><input type="hidden" name="v" value="4">
            <input name="q" placeholder="Search: Reg No / Name / Mobile / Email" value="<?=h($_GET['q']??'')?>">
            <select name="st">
              <?php $st=$_GET['st']??''; ?>
              <option value="">All Status</option>
              <?php foreach(['NEW','ACCEPTED','VERIFIED','REGISTERED','REJECTED'] as $sopt): ?>
                <option value="<?=$sopt?>" <?=($st===$sopt?'selected':'')?>><?=$sopt?></option>
              <?php endforeach; ?>
            </select>
            <input name="course" placeholder="Course" value="<?=h($_GET['course']??'')?>">
            <input name="session" placeholder="Session" value="<?=h($_GET['session']??'')?>">
            <button class="btn">Search</button>
            <a class="btn ghost" href="staff_dashboard.php?h=2&v=4">Reset</a>
          </form>
          <?php
            $q=trim($_GET['q']??'');
            $st=trim($_GET['st']??'');
            $course=trim($_GET['course']??'');
            $session=trim($_GET['session']??'');
            $w=[];$params=[];$types="";
            if($q!==""){ $w[]="(reg_no LIKE ? OR student_name LIKE ? OR mobile LIKE ? OR email LIKE ?)"; $types.="ssss"; $qq="%$q%"; $params=array_merge($params,[$qq,$qq,$qq,$qq]); }
            if($st!==""){ $w[]="status=?"; $types.="s"; $params[]=$st; }
            if($course!==""){ $w[]="course LIKE ?"; $types.="s"; $params[]="%$course%"; }
            if($session!==""){ $w[]="session LIKE ?"; $types.="s"; $params[]="%$session%"; }
            $where = $w?("WHERE ".implode(" AND ",$w)):"";
            $sql="SELECT id, reg_no, student_name, mobile, email, course, semester_apply, session, status, apply_date, total_fees FROM `$T_ADM` $where ORDER BY id DESC LIMIT 500";
            $stmt=$conn->prepare($sql);
            $rows=[];
            if($stmt){
              if($types) $stmt->bind_param($types,...$params);
              $stmt->execute();
              $res=$stmt->get_result();
              $rows=$res?$res->fetch_all(MYSQLI_ASSOC):[];
              $stmt->close();
            }
          ?>
          <table class="tbl">
            <tr><th>ID</th><th>Reg No</th><th>Name</th><th>Mobile</th><th>Course</th><th>Semester</th><th>Session</th><th>Status</th><th>Action</th></tr>
            <?php if(!$rows): ?><tr><td colspan="9" class="muted">No records found.</td></tr><?php endif; ?>
            <?php foreach($rows as $rw): ?>
              <tr>
                <td><?=h($rw["id"])?></td>
                <td><?=h($rw["reg_no"])?></td>
                <td><?=h($rw["student_name"])?></td>
                <td><?=h($rw["mobile"])?></td>
                <td><?=h($rw["course"])?></td>
                <td><?=h($rw["semester_apply"])?></td>
                <td><?=h($rw["session"])?></td>
                <td><?=h($rw["status"])?></td>
                <td>
                  <button type="button" class="btn tiny" onclick="openAdmission(<?= (int)$rw['id'] ?>)">View</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>

        <?php elseif($V===5): ?>
          <h2>Operations → Reports (Export / Bulk)</h2>
          <div class="muted">Select admissions → Export to Excel(CSV) / Bulk download documents (ZIP).</div>
          <form class="filters" method="get" action="staff_dashboard.php">
            <input type="hidden" name="h" value="2"><input type="hidden" name="v" value="5">
            <input name="q" placeholder="Search: Reg No / Name / Mobile" value="<?=h($_GET['q']??'')?>">
            <select name="st">
              <?php $st=$_GET['st']??''; ?>
              <option value="">All Status</option>
              <?php foreach(['NEW','ACCEPTED','VERIFIED','REGISTERED','REJECTED'] as $sopt): ?>
                <option value="<?=$sopt?>" <?=($st===$sopt?'selected':'')?>><?=$sopt?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn">Search</button>
            <a class="btn ghost" href="staff_dashboard.php?h=2&v=5">Reset</a>
          </form>
          <?php if($edit_row): ?>
            <div style="margin:14px 0;padding:12px;border:1px solid rgba(11,42,91,.18);border-radius:16px;background:#f8fbff">
              <div class="row" style="justify-content:space-between">
                <div style="font-weight:950">Edit Admission (ID: <?=h($edit_id)?>)</div>
                <a class="btn" href="staff_dashboard.php?h=2&v=5">Close</a>
              </div>
              <form method="post" style="margin-top:10px;display:grid;gap:10px;grid-template-columns:repeat(2,minmax(0,1fr));">
                <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                <input type="hidden" name="action" value="admission_update">
                <input type="hidden" name="id" value="<?=h($edit_id)?>">
                <label class="k">Student Name
                  <input class="inp" name="student_name" value="<?=h($edit_row['student_name'] ?? '')?>">
                </label>
                <label class="k">Mobile
                  <input class="inp" name="mobile" value="<?=h($edit_row['mobile'] ?? '')?>">
                </label>
                <label class="k">Email
                  <input class="inp" name="email" value="<?=h($edit_row['email'] ?? '')?>">
                </label>
                <label class="k">Course
                  <input class="inp" name="course" value="<?=h($edit_row['course'] ?? '')?>">
                </label>
                <label class="k">Semester
                  <input class="inp" name="semester_apply" value="<?=h($edit_row['semester_apply'] ?? '')?>">
                </label>
                <label class="k">Session
                  <input class="inp" name="session" value="<?=h($edit_row['session'] ?? '')?>">
                </label>
                <label class="k" style="grid-column:1/-1">Status
                  <?php $curStatus = strtoupper((string)($edit_row['status'] ?? 'NEW')); ?>
                  <select class="inp" name="status">
                    <?php foreach(['NEW','ACCEPTED','VERIFIED','REGISTERED','REJECTED'] as $sopt): ?>
                      <option value="<?=$sopt?>" <?=($curStatus===$sopt?'selected':'')?>><?=$sopt?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <div style="grid-column:1/-1;display:flex;gap:10px;align-items:center">
                  <button class="btn primary" type="submit">Save Changes</button>
                  <a class="btn" href="staff_dashboard.php?h=2&v=5">Cancel</a>
                </div>
              </form>
            </div>
          <?php endif; ?>
          <?php
            $q=trim($_GET['q']??''); $st=trim($_GET['st']??'');
            $w=[];$params=[];$types="";
            if($q!==""){ $w[]="(reg_no LIKE ? OR student_name LIKE ? OR mobile LIKE ?)"; $types.="sss"; $qq="%$q%"; $params=array_merge($params,[$qq,$qq,$qq]); }
            if($st!==""){ $w[]="status=?"; $types.="s"; $params[]=$st; }
            $where = $w?("WHERE ".implode(" AND ",$w)):"";
            $sql="SELECT id, reg_no, student_name, mobile, course, session, status FROM `$T_ADM` $where ORDER BY id DESC LIMIT 1000";
            $stmt=$conn->prepare($sql);
            $rows=[];
            if($stmt){
              if($types) $stmt->bind_param($types,...$params);
              $stmt->execute();
              $res=$stmt->get_result();
              $rows=$res?$res->fetch_all(MYSQLI_ASSOC):[];
              $stmt->close();
            }
          ?>
          <form method="post" id="reportForm">
            <input type="hidden" name="csrf" value="<?=h($csrf)?>">
            <input type="hidden" name="action" value="report_action">
            <div style="display:flex;gap:10px;align-items:center;margin:10px 0;">
              <select name="do" required>
                <option value="">Select Action</option>
                <option value="export_csv">Export to Excel (CSV)</option>
                <option value="bulk_zip">Bulk Download Documents (ZIP)</option>
                <option value="bulk_forms_zip">Bulk Download Forms + Docs (ZIP)</option>
              </select>
              <button class="btn">Run</button>
              <button type="button" class="btn ghost" onclick="toggleAll(true)">Select All</button>
              <button type="button" class="btn ghost" onclick="toggleAll(false)">Clear</button>
            </div>
            <table class="tbl">
              <tr><th><input type="checkbox" onclick="toggleAll(this.checked)"></th><th>ID</th><th>Reg No</th><th>Name</th><th>Mobile</th><th>Course</th><th>Session</th><th>Status</th><th>View</th></tr>
              <?php if(!$rows): ?><tr><td colspan="9" class="muted">No records found.</td></tr><?php endif; ?>
              <?php foreach($rows as $rw): ?>
                <tr>
                  <td><input class="selbox" type="checkbox" name="ids[]" value="<?= (int)$rw['id'] ?>"></td>
                  <td><?=h($rw["id"])?></td>
                  <td><?=h($rw["reg_no"])?></td>
                  <td><?=h($rw["student_name"])?></td>
                  <td><?=h($rw["mobile"])?></td>
                  <td><?=h($rw["course"])?></td>
                  <td><?=h($rw["session"])?></td>
                  <td><?=h($rw["status"])?></td>
                  <td>
                    <button type="button" class="btn tiny" onclick="openAdmission(<?= (int)$rw['id'] ?>)">View</button>
                    <a class="btn tiny" href="staff_dashboard.php?h=2&v=5&edit=<?= (int)$rw['id'] ?>">Edit</a>
                    <button class="btn tiny danger" type="submit" form="delete-<?= (int)$rw['id'] ?>" onclick="return confirm('Delete this admission?');">Delete</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </table>
          </form>
          <?php foreach($rows as $rw): ?>
            <form id="delete-<?= (int)$rw['id'] ?>" method="post">
              <input type="hidden" name="csrf" value="<?=h($csrf)?>">
              <input type="hidden" name="action" value="admission_delete">
              <input type="hidden" name="id" value="<?= (int)$rw['id'] ?>">
            </form>
          <?php endforeach; ?>

        <?php else: ?>
          <h2>Operations</h2>
          <div class="muted">Select a module from left menu.</div>
        <?php endif; ?>


      <?php elseif($H===3): ?>
        <?php if($V===1): ?>
          <h2 class="title">Fees & Accounting → Fees Dashboard</h2>
          <p class="sub">Fees summary across all admissions.</p>
          <?php
            $feesSummary = ["total"=>0,"paid"=>0,"due"=>0,"count"=>0];
            $r = $conn->query("SELECT COUNT(*) as total_students, SUM(IFNULL(total_fees,0)) as total_fees, SUM(IFNULL(fees_paid,0)) as total_paid, SUM(IFNULL(fees_due,0)) as total_due FROM `$T_ENQ`");
            if ($r) { $feesSummary = $r->fetch_assoc(); $r->free(); }
            $lowPaidCount = 0;
            $r = $conn->query("SELECT COUNT(*) as low_paid FROM `$T_ENQ` WHERE IFNULL(total_fees,0) > 0 AND (IFNULL(fees_paid,0) / IFNULL(total_fees,0)) < 0.5");
            if ($r) { $row = $r->fetch_assoc(); $lowPaidCount = (int)($row["low_paid"] ?? 0); $r->free(); }
          ?>
          <div class="row" style="margin-top:12px">
            <div class="card" style="flex:1;min-width:180px">
              <div class="mut">Total Students</div>
              <div style="font-weight:950;font-size:20px"><?=h($feesSummary['total_students'] ?? 0)?></div>
            </div>
            <div class="card" style="flex:1;min-width:180px">
              <div class="mut">Total Fees</div>
              <div style="font-weight:950;font-size:20px"><?=h($feesSummary['total_fees'] ?? 0)?></div>
            </div>
            <div class="card" style="flex:1;min-width:180px">
              <div class="mut">Total Paid</div>
              <div style="font-weight:950;font-size:20px"><?=h($feesSummary['total_paid'] ?? 0)?></div>
            </div>
            <div class="card" style="flex:1;min-width:180px">
              <div class="mut">Total Due</div>
              <div style="font-weight:950;font-size:20px"><?=h($feesSummary['total_due'] ?? 0)?></div>
            </div>
            <div class="card" style="flex:1;min-width:180px;border-color:#fecaca;background:#fff5f5">
              <div class="mut">Paid &lt; 50%</div>
              <div style="font-weight:950;font-size:20px;color:#b91c1c"><?=h($lowPaidCount)?></div>
            </div>
          </div>
        <?php elseif($V===2): ?>
          <h2 class="title">Fees & Accounting → Fee Transactions</h2>
          <p class="sub">Record a payment and update student fee balances.</p>
          <?php
            $q=trim($_GET['q']??'');
            $w=[];$params=[];$types="";
            if($q!==""){ $w[]="(reg_no LIKE ? OR student_name LIKE ? OR mobile LIKE ?)"; $types.="sss"; $qq="%$q%"; $params=array_merge($params,[$qq,$qq,$qq]); }
            $where = $w?("WHERE ".implode(" AND ",$w)):"";
            $sql="SELECT id, reg_no, student_name, mobile, semester_apply, total_fees, fees_paid, fees_due FROM `$T_ENQ` $where ORDER BY id DESC LIMIT 200";
            $stmt=$conn->prepare($sql);
            $fee_rows=[];
            if($stmt){
              if($types) $stmt->bind_param($types,...$params);
              $stmt->execute();
              $res=$stmt->get_result();
              $fee_rows=$res?$res->fetch_all(MYSQLI_ASSOC):[];
              $stmt->close();
            }
          ?>
          <form class="filters" method="get" action="staff_dashboard.php">
            <input type="hidden" name="h" value="3"><input type="hidden" name="v" value="2">
            <input name="q" placeholder="Search: Reg No / Name / Mobile" value="<?=h($_GET['q']??'')?>">
            <button class="btn">Search</button>
            <a class="btn ghost" href="staff_dashboard.php?h=3&v=2">Reset</a>
          </form>
          <table class="tbl">
            <tr>
              <th>Reg No</th>
              <th>Name</th>
              <th>Mobile</th>
              <th>Semester</th>
              <th>Total Fees</th>
              <th>Total Paid</th>
              <th>Total Due</th>
              <th>Pay Now</th>
            </tr>
            <?php if(!$fee_rows): ?><tr><td colspan="8" class="muted">No records found.</td></tr><?php endif; ?>
            <?php foreach($fee_rows as $fr): ?>
              <tr>
                <td><?=h($fr["reg_no"] ?? "")?></td>
                <td><?=h($fr["student_name"] ?? "")?></td>
                <td><?=h($fr["mobile"] ?? "")?></td>
                <td><?=h($fr["semester_apply"] ?? "")?></td>
                <td><?=h($fr["total_fees"] ?? "0")?></td>
                <td><?=h($fr["fees_paid"] ?? "0")?></td>
                <td><?=h(isset($fr["fees_due"]) ? $fr["fees_due"] : (float)($fr["total_fees"] ?? 0) - (float)($fr["fees_paid"] ?? 0))?></td>
                <td>
                  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                    <input type="hidden" name="action" value="fee_collect">
                    <input type="hidden" name="id" value="<?=h($fr['id'])?>">
                    <input class="inp" name="amount" type="number" step="0.01" min="0" placeholder="Amount" style="width:100px" required>
                    <select class="inp" name="pay_mode">
                      <?php foreach(["CASH","UPI","ONLINE","BANK"] as $pm): ?>
                        <option value="<?=$pm?>"><?=$pm?></option>
                      <?php endforeach; ?>
                    </select>
                    <input class="inp" name="txn_id" placeholder="Txn/Ref" style="width:120px">
                    <input class="inp" name="pay_date" type="date" value="<?=date('Y-m-d')?>">
                    <button class="btn ok" type="submit">Pay</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php else: ?>
          <h2 class="title">Fees & Accounting → Payment History</h2>
          <p class="sub">Recent fee entries recorded by staff.</p>
          <?php
            $r = $conn->query("SELECT p.*, a.reg_no, a.student_name, a.mobile FROM `$T_FEE_PAYMENTS` p LEFT JOIN `$T_ENQ` a ON a.id = p.admission_id ORDER BY p.id DESC LIMIT 200");
            $pay_rows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
            if ($r) { $r->free(); }
          ?>
          <table class="tbl">
            <tr><th>ID</th><th>Reg No</th><th>Name</th><th>Mobile</th><th>Amount</th><th>Mode</th><th>Txn/Ref</th><th>Pay Date</th><th>Status</th></tr>
            <?php if(!$pay_rows): ?><tr><td colspan="9" class="muted">No payment entries found.</td></tr><?php endif; ?>
            <?php foreach($pay_rows as $pr): ?>
              <tr>
                <td><?=h($pr["id"] ?? "")?></td>
                <td><?=h($pr["reg_no"] ?? "")?></td>
                <td><?=h($pr["student_name"] ?? "")?></td>
                <td><?=h($pr["mobile"] ?? "")?></td>
                <td><?=h($pr["amount"] ?? "")?></td>
                <td><?=h($pr["pay_mode"] ?? "")?></td>
                <td><?=h($pr["txn_id"] ?? "")?></td>
                <td><?=h($pr["pay_date"] ?? "")?></td>
                <td><?=h($pr["status"] ?? "")?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?><?php elseif($H===4): ?>
<?php if($V===1): ?>
  <h2 class="title">Notification → Notice & News</h2>
  <p class="sub">Create/Update Notice or News. Website popup (every visit) + News headline scrolling. Student portal me login ke baad popup. (Target: WEBSITE / PORTAL / BOTH)</p>

  <div class="card" style="margin-bottom:14px">
    <h3 style="margin:0 0 10px 0;font-size:16px">Add / Update</h3>
    <form method="post" enctype="multipart/form-data" class="form-grid">
      <input type="hidden" name="csrf" value="<?=$csrf?>">
      <input type="hidden" name="action" value="notice_save">
      <input type="hidden" name="notice_id" id="notice_id" value="0">

      <div class="frow">
        <label>Type</label>
        <select name="kind" id="kind">
          <option value="NOTICE">NOTICE</option>
          <option value="NEWS">NEWS</option>
        </select>
      </div>

      <div class="frow">
        <label>Target</label>
        <select name="target" id="target">
          <option value="BOTH">BOTH (Website + Student Portal)</option>
          <option value="WEBSITE">WEBSITE only</option>
          <option value="STUDENT_PORTAL">STUDENT PORTAL only</option>
        </select>
      </div>

      <div class="frow" style="grid-column:1/-1">
        <label>Title *</label>
        <input type="text" name="title" id="title" placeholder="Title" required>
      </div>

      <div class="frow" style="grid-column:1/-1">
        <label>Description</label>
        <textarea name="description" id="description" rows="4" placeholder="Description"></textarea>
      </div>

      <div class="frow">
        <label>Date</label>
        <input type="date" name="notice_date" id="notice_date" value="<?=date('Y-m-d')?>">
      </div>

      <div class="frow">
        <label>Attachment (PDF/Image)</label>
        <input type="file" name="attachment" accept=".pdf,.png,.jpg,.jpeg,.webp">
      </div>

      <div class="frow">
        <label><input type="checkbox" name="is_popup" id="is_popup" value="1"> Popup (Website/Portal)</label>
      </div>

      <div class="frow">
        <label><input type="checkbox" name="is_scrolling" id="is_scrolling" value="1"> Scrolling headline (NEWS)</label>
      </div>

      <div class="frow">
        <label><input type="checkbox" name="is_active" id="is_active" value="1" checked> Active</label>
      </div>

      <div class="frow" style="grid-column:1/-1;display:flex;gap:10px;align-items:center">
        <button class="btn primary" type="submit">Save</button>
        <button class="btn" type="button" onclick="resetNoticeForm()">Clear</button>
        <span class="sub" id="edit_hint" style="margin-left:8px"></span>
      </div>
    </form>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px 0;font-size:16px">List</h3>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Type</th>
            <th>Title</th>
            <th>Date</th>
            <th>Target</th>
            <th>Popup</th>
            <th>Scroll</th>
            <th>Active</th>
            <th>Attachment</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($notice_rows)): ?>
            <tr><td colspan="10" class="muted">No notices/news found.</td></tr>
          <?php else: ?>
            <?php foreach($notice_rows as $nr): ?>
              <tr>
                <td><?=h($nr['id']??'')?></td>
                <td><?=h($nr['kind'] ?? ($nr['type'] ?? ''))?></td>
                <td><?=h($nr['title']??'')?></td>
                <td><?=h($nr['notice_date']??'')?></td>
                <td><?=h($nr['target']??'')?></td>
                <td><?=!empty($nr['is_popup'])?'Yes':'No'?></td>
                <td><?=!empty($nr['is_scrolling'])?'Yes':'No'?></td>
                <td><?=!empty($nr['is_active'])?'Yes':'No'?></td>
                <td>
                  <?php if(!empty($nr['attachment_path'])): ?>
                    <a class="link" href="<?=h($nr['attachment_path'])?>" target="_blank">View</a>
                  <?php else: ?>-<?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                  <button class="btn sm" type="button"
                    onclick='editNotice(<?=json_encode($nr, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>)'>Edit</button>
                  <form method="post" style="display:inline" onsubmit="return confirm('Delete this notice/news?')">
                    <input type="hidden" name="csrf" value="<?=$csrf?>">
                    <input type="hidden" name="action" value="notice_delete">
                    <input type="hidden" name="notice_id" value="<?=h($nr['id'])?>">
                    <button class="btn sm danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <script>
    function resetNoticeForm(){
      document.getElementById('notice_id').value = '0';
      document.getElementById('kind').value = 'NOTICE';
      document.getElementById('target').value = 'BOTH';
      document.getElementById('title').value = '';
      document.getElementById('description').value = '';
      document.getElementById('notice_date').value = '<?=date('Y-m-d')?>';
      document.getElementById('is_popup').checked = false;
      document.getElementById('is_scrolling').checked = false;
      document.getElementById('is_active').checked = true;
      document.getElementById('edit_hint').textContent = '';
      window.scrollTo({top:0,behavior:'smooth'});
    }
    function editNotice(n){
      document.getElementById('notice_id').value = n.id || 0;
      document.getElementById('kind').value = (n.kind || n.type || 'NOTICE');
      document.getElementById('target').value = (n.target || 'BOTH');
      document.getElementById('title').value = n.title || '';
      document.getElementById('description').value = n.description || '';
      document.getElementById('notice_date').value = n.notice_date || '<?=date('Y-m-d')?>';
      document.getElementById('is_popup').checked = (parseInt(n.is_popup||0)===1);
      document.getElementById('is_scrolling').checked = (parseInt(n.is_scrolling||0)===1);
      document.getElementById('is_active').checked = (parseInt(n.is_active||0)!==0);
      document.getElementById('edit_hint').textContent = 'Editing ID: ' + (n.id||'');
      window.scrollTo({top:0,behavior:'smooth'});
    }
  </script>

<?php elseif($V===2): ?>
  <h2 class="title">Notification → Slider Images</h2>
  <p class="sub">Website home slider images add/remove. Only image upload.</p>

  <div class="card" style="margin-bottom:14px">
    <h3 style="margin:0 0 10px 0;font-size:16px">Upload</h3>
    <form method="post" enctype="multipart/form-data" class="form-grid">
      <input type="hidden" name="csrf" value="<?=$csrf?>">
      <input type="hidden" name="action" value="slider_upload">

      <div class="frow" style="grid-column:1/-1">
        <label>Image *</label>
        <input type="file" name="slider_image" accept=".png,.jpg,.jpeg,.webp" required>
      </div>

      <div class="frow">
        <label>Sort Order</label>
        <input type="number" name="sort_order" value="0">
      </div>

      <div class="frow">
        <label><input type="checkbox" name="is_active" value="1" checked> Active</label>
      </div>

      <div class="frow" style="grid-column:1/-1">
        <button class="btn primary" type="submit">Upload</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px 0;font-size:16px">Current Slider Images</h3>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Preview</th>
            <th>Path</th>
            <th>Sort</th>
            <th>Active</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($slider_rows)): ?>
            <tr><td colspan="6" class="muted">No slider images found.</td></tr>
          <?php else: ?>
            <?php foreach($slider_rows as $sr): ?>
              <tr>
                <td><?=h($sr['id']??'')?></td>
                <td><?php if(!empty($sr['image_path'])): ?><img src="<?=h($sr['image_path'])?>" alt="" style="width:120px;height:50px;object-fit:cover;border-radius:10px;border:1px solid rgba(0,0,0,.08)"><?php endif; ?></td>
                <td><?=h($sr['image_path']??'')?></td>
                <td><?=h($sr['sort_order']??'0')?></td>
                <td><?=!empty($sr['is_active'])?'Yes':'No'?></td>
                <td>
                  <form method="post" style="display:inline" onsubmit="return confirm('Remove this slider image?')">
                    <input type="hidden" name="csrf" value="<?=$csrf?>">
                    <input type="hidden" name="action" value="slider_delete">
                    <input type="hidden" name="slider_id" value="<?=h($sr['id'])?>">
                    <button class="btn sm danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif($V===3): ?>
  <h2 class="title">Notification → Direct Message (Student)</h2>
  <p class="sub">Specific student ko message send. Student portal me popup highlight + acknowledge required. Expiry date/time supported.</p>

  <div class="card" style="margin-bottom:14px">
    <h3 style="margin:0 0 10px 0;font-size:16px">Send Message</h3>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?=$csrf?>">
      <input type="hidden" name="action" value="msg_send">

      <div class="frow">
        <label>Student User ID (users.id) *</label>
        <input type="number" name="student_user_id" required>
      </div>

      <div class="frow">
        <label>Priority (higher = first)</label>
        <input type="number" name="priority" value="10">
      </div>

      <div class="frow" style="grid-column:1/-1">
        <label>Message *</label>
        <textarea name="message" rows="4" required placeholder="Type message..."></textarea>
      </div>

      <div class="frow">
        <label>Expiry (date/time)</label>
        <input type="datetime-local" name="expires_at">
      </div>

      <div class="frow" style="grid-column:1/-1">
        <button class="btn primary" type="submit">Send</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px 0;font-size:16px">Sent Messages</h3>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Student</th>
            <th>Message</th>
            <th>Priority</th>
            <th>Expiry</th>
            <th>Acknowledged</th>
            <th>Active</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($msg_rows)): ?>
            <tr><td colspan="8" class="muted">No messages found.</td></tr>
          <?php else: ?>
            <?php foreach($msg_rows as $mr): ?>
              <tr>
                <td><?=h($mr['id']??'')?></td>
                <td>
                  <?=h($mr['student_name']??'')?><br>
                  <span class="muted"><?=h($mr['student_email']??'')?></span><br>
                  <span class="muted">ID: <?=h($mr['student_user_id']??'')?></span>
                </td>
                <td style="max-width:420px"><?=h($mr['message']??'')?></td>
                <td><?=h($mr['priority']??'')?></td>
                <td><?=h($mr['expires_at']??'')?></td>
                <td><?=!empty($mr['acknowledged_at'])?h($mr['acknowledged_at']):'-'?></td>
                <td><?=!empty($mr['is_active'])?'Yes':'No'?></td>
                <td>
                  <?php if(!empty($mr['is_active'])): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Deactivate this message?')">
                      <input type="hidden" name="csrf" value="<?=$csrf?>">
                      <input type="hidden" name="action" value="msg_deactivate">
                      <input type="hidden" name="msg_id" value="<?=h($mr['id'])?>">
                      <button class="btn sm" type="submit">Deactivate</button>
                    </form>
                  <?php else: ?>-<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

      <?php elseif($H===6): ?>
        <?php if($V===1): ?>
          <h2 class="title">E-Digital Library → Upload Books</h2>
          <p class="sub">Upload PDF or image files with subject and semester.</p>
          <div class="card" style="margin-top:12px">
            <form method="post" enctype="multipart/form-data" class="form-grid">
              <input type="hidden" name="csrf" value="<?=h($csrf)?>">
              <input type="hidden" name="action" value="library_upload">
              <div class="frow">
                <label>Subject Name *</label>
                <input class="inp" name="subject" required>
              </div>
              <div class="frow">
                <label>Semester *</label>
                <select class="inp" name="semester" required>
                  <option value="">Select Semester</option>
                  <?php for($i=1;$i<=6;$i++): ?>
                    <option value="<?=$i?>">Semester <?=$i?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="frow" style="grid-column:1/-1">
                <label>File (PDF/Image) *</label>
                <input type="file" name="library_file" accept=".pdf,.png,.jpg,.jpeg,.webp" required>
              </div>
              <div class="frow" style="grid-column:1/-1">
                <button class="btn primary" type="submit">Upload</button>
              </div>
            </form>
          </div>
        <?php else: ?>
          <h2 class="title">E-Digital Library → Book List</h2>
          <p class="sub">Search and manage uploaded library files.</p>
          <?php
            $q = trim($_GET["q"] ?? "");
            $w = [];
            $params = [];
            $types = "";
            if ($q !== "") {
              $w[] = "(subject LIKE ? OR tag LIKE ? OR file_name LIKE ?)";
              $qq = "%".$q."%";
              $params = array_merge($params, [$qq,$qq,$qq]);
              $types .= "sss";
            }
            $where = $w ? ("WHERE ".implode(" AND ", $w)) : "";
            $edit_lib = (int)($_GET["edit_lib"] ?? 0);
            $edit_lib_row = null;
            if ($edit_lib > 0) {
              if (db_has_table($conn, $T_LIBRARY)) {
                $stmt = $conn->prepare("SELECT * FROM `$T_LIBRARY` WHERE id=? LIMIT 1");
              } else {
                $stmt = $conn->prepare("SELECT * FROM `$T_UPLOADS` WHERE id=? LIMIT 1");
              }
              if ($stmt) {
                $stmt->bind_param("i", $edit_lib);
                $stmt->execute();
                $res = $stmt->get_result();
                $edit_lib_row = $res ? $res->fetch_assoc() : null;
                $stmt->close();
              }
            }
            if (db_has_table($conn, $T_LIBRARY)) {
              $sql = "SELECT id, subject, semester, file_path, file_type, created_at FROM `$T_LIBRARY` $where ORDER BY id DESC LIMIT 300";
            } else {
              $sql = "SELECT id, tag as subject, sort_order as semester, file_path, file_type, file_name, created_at FROM `$T_UPLOADS` $where ORDER BY id DESC LIMIT 300";
            }
            $stmt = $conn->prepare($sql);
            $lib_rows = [];
            if ($stmt) {
              if ($types) { $stmt->bind_param($types, ...$params); }
              $stmt->execute();
              $res = $stmt->get_result();
              $lib_rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
              $stmt->close();
            }
          ?>
          <form class="filters" method="get" action="staff_dashboard.php">
            <input type="hidden" name="h" value="6"><input type="hidden" name="v" value="2">
            <input name="q" placeholder="Search: Subject / File" value="<?=h($q)?>">
            <button class="btn">Search</button>
            <a class="btn ghost" href="staff_dashboard.php?h=6&v=2">Reset</a>
          </form>
          <?php if($edit_lib_row): ?>
            <div style="margin:14px 0;padding:12px;border:1px solid rgba(11,42,91,.18);border-radius:16px;background:#f8fbff">
              <div class="row" style="justify-content:space-between">
                <div style="font-weight:950">Edit Book (ID: <?=h($edit_lib)?>)</div>
                <a class="btn" href="staff_dashboard.php?h=6&v=2">Close</a>
              </div>
              <form method="post" style="margin-top:10px;display:grid;gap:10px;grid-template-columns:repeat(2,minmax(0,1fr));">
                <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                <input type="hidden" name="action" value="library_update">
                <input type="hidden" name="lib_id" value="<?=h($edit_lib)?>">
                <label class="k">Subject Name
                  <input class="inp" name="subject" value="<?=h($edit_lib_row["subject"] ?? ($edit_lib_row["tag"] ?? ""))?>">
                </label>
                <label class="k">Semester
                  <input class="inp" name="semester" type="number" min="1" max="6" value="<?=h($edit_lib_row["semester"] ?? ($edit_lib_row["sort_order"] ?? ""))?>">
                </label>
                <div style="grid-column:1/-1;display:flex;gap:10px;align-items:center">
                  <button class="btn primary" type="submit">Save Changes</button>
                  <a class="btn" href="staff_dashboard.php?h=6&v=2">Cancel</a>
                </div>
              </form>
            </div>
          <?php endif; ?>
          <table class="tbl">
            <tr><th>ID</th><th>Subject</th><th>Semester</th><th>File</th><th>Type</th><th>Action</th></tr>
            <?php if(!$lib_rows): ?><tr><td colspan="6" class="muted">No books found.</td></tr><?php endif; ?>
            <?php foreach($lib_rows as $lr): ?>
              <tr>
                <td><?=h($lr["id"] ?? "")?></td>
                <td><?=h($lr["subject"] ?? "")?></td>
                <td><?=h($lr["semester"] ?? "")?></td>
                <td>
                  <?php if(!empty($lr["file_path"])): ?>
                    <a class="link" href="<?=h($lr["file_path"])?>" target="_blank">Open</a>
                  <?php else: ?>-<?php endif; ?>
                </td>
                <td><?=h($lr["file_type"] ?? "")?></td>
                <td>
                  <a class="btn tiny" href="staff_dashboard.php?h=6&v=2&edit_lib=<?= (int)($lr['id'] ?? 0) ?>">Edit</a>
                  <form method="post" style="display:inline" onsubmit="return confirm('Delete this book?');">
                    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                    <input type="hidden" name="action" value="library_delete">
                    <input type="hidden" name="lib_id" value="<?= (int)($lr['id'] ?? 0) ?>">
                    <button class="btn tiny danger">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      <?php elseif($H===7): ?>
        <?php if($V===1): ?>
          <h2 class="title">E-Classes → Upload Video Lecture</h2>
          <p class="sub">Add subject, semester, unit, topic, and video details.</p>
          <div class="card" style="margin-top:12px">
            <form method="post" enctype="multipart/form-data" class="form-grid">
              <input type="hidden" name="csrf" value="<?=h($csrf)?>">
              <input type="hidden" name="action" value="eclass_upload">
              <div class="frow">
                <label>Subject Name *</label>
                <input class="inp" name="subject" required>
              </div>
              <div class="frow">
                <label>Semester *</label>
                <select class="inp" name="semester" required>
                  <option value="">Select Semester</option>
                  <?php for($i=1;$i<=6;$i++): ?>
                    <option value="<?=$i?>">Semester <?=$i?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="frow">
                <label>Unit No *</label>
                <input class="inp" name="unit_no" required>
              </div>
              <div class="frow">
                <label>Unit Name *</label>
                <input class="inp" name="unit_name" required>
              </div>
              <div class="frow" style="grid-column:1/-1">
                <label>Topic Name *</label>
                <input class="inp" name="topic" required>
              </div>
              <div class="frow" style="grid-column:1/-1">
                <label>Video Link *</label>
                <input class="inp" name="link" required>
              </div>
              <div class="frow" style="grid-column:1/-1">
                <label>Description</label>
                <textarea class="inp" name="description" rows="3"></textarea>
              </div>
              <div class="frow" style="grid-column:1/-1">
                <label>Upload File (optional)</label>
                <input type="file" name="lecture_file" accept=".mp4,.pdf,.png,.jpg,.jpeg,.webp">
              </div>
              <div class="frow" style="grid-column:1/-1">
                <button class="btn primary" type="submit">Submit</button>
              </div>
            </form>
          </div>
        <?php else: ?>
          <h2 class="title">E-Classes → Online Video Lectures</h2>
          <p class="sub">Manage video lecture links and details.</p>
          <?php
            $q = trim($_GET["q"] ?? "");
            $w = [];
            $params = [];
            $types = "";
            if ($q !== "") {
              $w[] = "(subject LIKE ? OR unit_name LIKE ? OR topic LIKE ?)";
              $qq = "%".$q."%";
              $params = array_merge($params, [$qq,$qq,$qq]);
              $types .= "sss";
            }
            $where = $w ? ("WHERE ".implode(" AND ", $w)) : "";
            $edit_vid = (int)($_GET["edit_vid"] ?? 0);
            $edit_vid_row = null;
            if ($edit_vid > 0 && db_has_table($conn, $T_ECLASS)) {
              $stmt = $conn->prepare("SELECT * FROM `$T_ECLASS` WHERE id=? LIMIT 1");
              if ($stmt) {
                $stmt->bind_param("i", $edit_vid);
                $stmt->execute();
                $res = $stmt->get_result();
                $edit_vid_row = $res ? $res->fetch_assoc() : null;
                $stmt->close();
              }
            }
            $video_rows = [];
            if (db_has_table($conn, $T_ECLASS)) {
              $sql = "SELECT * FROM `$T_ECLASS` $where ORDER BY id DESC LIMIT 300";
              $stmt = $conn->prepare($sql);
              if ($stmt) {
                if ($types) { $stmt->bind_param($types, ...$params); }
                $stmt->execute();
                $res = $stmt->get_result();
                $video_rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
                $stmt->close();
              }
            }
          ?>
          <form class="filters" method="get" action="staff_dashboard.php">
            <input type="hidden" name="h" value="7"><input type="hidden" name="v" value="2">
            <input name="q" placeholder="Search: Subject / Unit / Topic" value="<?=h($q)?>">
            <button class="btn">Search</button>
            <a class="btn ghost" href="staff_dashboard.php?h=7&v=2">Reset</a>
          </form>
          <?php if($edit_vid_row): ?>
            <div style="margin:14px 0;padding:12px;border:1px solid rgba(11,42,91,.18);border-radius:16px;background:#f8fbff">
              <div class="row" style="justify-content:space-between">
                <div style="font-weight:950">Edit Lecture (ID: <?=h($edit_vid)?>)</div>
                <a class="btn" href="staff_dashboard.php?h=7&v=2">Close</a>
              </div>
              <form method="post" style="margin-top:10px;display:grid;gap:10px;grid-template-columns:repeat(2,minmax(0,1fr));">
                <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                <input type="hidden" name="action" value="eclass_update">
                <input type="hidden" name="vid" value="<?=h($edit_vid)?>">
                <label class="k">Subject Name
                  <input class="inp" name="subject" value="<?=h($edit_vid_row["subject"] ?? "")?>">
                </label>
                <label class="k">Semester
                  <input class="inp" name="semester" type="number" min="1" max="6" value="<?=h($edit_vid_row["semester"] ?? "")?>">
                </label>
                <label class="k">Unit No
                  <input class="inp" name="unit_no" value="<?=h($edit_vid_row["unit_no"] ?? "")?>">
                </label>
                <label class="k">Unit Name
                  <input class="inp" name="unit_name" value="<?=h($edit_vid_row["unit_name"] ?? "")?>">
                </label>
                <label class="k" style="grid-column:1/-1">Topic
                  <input class="inp" name="topic" value="<?=h($edit_vid_row["topic"] ?? "")?>">
                </label>
                <label class="k" style="grid-column:1/-1">Video Link
                  <input class="inp" name="link" value="<?=h($edit_vid_row["video_link"] ?? "")?>">
                </label>
                <label class="k" style="grid-column:1/-1">Description
                  <textarea class="inp" name="description" rows="3"><?=h($edit_vid_row["description"] ?? "")?></textarea>
                </label>
                <div style="grid-column:1/-1;display:flex;gap:10px;align-items:center">
                  <button class="btn primary" type="submit">Save Changes</button>
                  <a class="btn" href="staff_dashboard.php?h=7&v=2">Cancel</a>
                </div>
              </form>
            </div>
          <?php endif; ?>
          <table class="tbl">
            <tr><th>ID</th><th>Subject</th><th>Semester</th><th>Unit</th><th>Topic</th><th>Link</th><th>Action</th></tr>
            <?php if(!$video_rows): ?><tr><td colspan="7" class="muted">No video lectures found.</td></tr><?php endif; ?>
            <?php foreach($video_rows as $vr): ?>
              <tr>
                <td><?=h($vr["id"] ?? "")?></td>
                <td><?=h($vr["subject"] ?? "")?></td>
                <td><?=h($vr["semester"] ?? "")?></td>
                <td><?=h(($vr["unit_no"] ?? "")." - ".($vr["unit_name"] ?? ""))?></td>
                <td><?=h($vr["topic"] ?? "")?></td>
                <td><?php if(!empty($vr["video_link"])): ?><a class="link" href="<?=h($vr["video_link"])?>" target="_blank">Open</a><?php else: ?>-<?php endif; ?></td>
                <td>
                  <a class="btn tiny" href="staff_dashboard.php?h=7&v=2&edit_vid=<?= (int)($vr['id'] ?? 0) ?>">Edit</a>
                  <form method="post" style="display:inline" onsubmit="return confirm('Delete this lecture?');">
                    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                    <input type="hidden" name="action" value="eclass_delete">
                    <input type="hidden" name="vid" value="<?= (int)($vr['id'] ?? 0) ?>">
                    <button class="btn tiny danger">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      <?php else: ?>
        <?php if($V===1): ?>
          <h2 class="title">Enquiries → Dashboard</h2>
          <p class="sub">Website/portal enquiries ka summary.</p>
          <div style="margin-top:12px;padding:14px;border:1px dashed rgba(15,23,42,.20);border-radius:16px;background:#fff">
            Aap bolo “Start B,5,1” then main enquiries dashboard implement kar dunga.
          </div>
        <?php else: ?>
          <h2 class="title">Enquiries → Admission Enquiries</h2>
          <p class="sub">Admission enquiries list + view details.</p>
          <div style="margin-top:12px;padding:14px;border:1px dashed rgba(15,23,42,.20);border-radius:16px;background:#fff">
            Aap bolo “Start B,5,2” then main enquiries list implement kar dunga.
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>


<!-- Admission View Modal -->
<div id="admModal" class="modal" style="display:none;">
  <div class="modal-card">
    <div class="modal-head">
      <div><b>Admission Form</b></div>
      <button type="button" class="btn tiny" onclick="closeAdmission()">Close</button>
    </div>
    <div id="admBody" class="modal-body">Loading...</div>
  </div>
</div>
<script>
function toggleAll(on){
  document.querySelectorAll('.selbox').forEach(cb=>cb.checked=!!on);
}
function openAdmission(id){
  const m=document.getElementById('admModal');
  const b=document.getElementById('admBody');
  m.style.display='block';
  b.innerHTML='Loading...';
  fetch(window.location.pathname + '?ajax=admission&id='+encodeURIComponent(id))
    .then(r=>r.json())
    .then(j=>{
      if(!j.ok){ b.innerHTML='<div class="muted">'+(j.error||'Error')+'</div>'; return; }
      const d=j.data||{};
      const docs=j.docs||{};
      let html='<div class="grid2">';
      Object.keys(d).forEach(k=>{
        const v=(d[k]===null||d[k]===undefined)?'':String(d[k]);
        html+=`<div class="kv"><div class="k">${k}</div><div class="v">${escapeHtml(v)}</div></div>`;
      });
      html+='</div>';
      const docKeys=Object.keys(docs);
      if(docKeys.length){
        html+='<hr><div><b>Documents</b></div><ul>';
        docKeys.forEach(k=>{
          const v=docs[k];
          const url = v.startsWith('http')?v:('./'+v.replace(/^\/+/,''));
          html+=`<li><a href="${url}" target="_blank" rel="noopener">${k}</a></li>`;
        });
        html+='</ul>';
      }
      b.innerHTML=html;
    })
    .catch(e=>{ b.innerHTML='<div class="muted">Error loading form.</div>'; });
}
function closeAdmission(){
  document.getElementById('admModal').style.display='none';
}
function escapeHtml(str){
  return str.replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[s]));
}
</script>
<style>
.modal{position:fixed;left:0;top:0;right:0;bottom:0;background:rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;z-index:9999;padding:20px;}
.modal-card{background:#fff;border-radius:16px;max-width:900px;width:100%;max-height:85vh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,.25);}
.modal-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #eee;}
.modal-body{padding:16px;}
.grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
.kv{border:1px solid #eee;border-radius:12px;padding:10px;}
.k{font-size:12px;color:#6b7280;margin-bottom:4px;}
.v{font-size:14px;color:#111827;word-break:break-word;}
@media(max-width:720px){.grid2{grid-template-columns:1fr;}}
</style>

</body>
</html>
