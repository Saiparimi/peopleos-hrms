<?php
// Session/authentication helpers for the local HRMS.
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','samesite'=>'Lax']);
    session_start();
}
require_once __DIR__.'/db.php';
if (empty($_SESSION['hrms_csrf'])) $_SESSION['hrms_csrf'] = bin2hex(random_bytes(32));
function h($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="'.h($_SESSION['hrms_csrf'] ?? '').'">'; }
function csrf_verify(): void {
    if ($_SERVER['REQUEST_METHOD']==='POST' && !hash_equals($_SESSION['hrms_csrf'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(419); exit('Security token expired. Refresh the page and try again.');
    }
}
function current_user(): ?array { return $_SESSION['hrms_user'] ?? null; }
function require_login(): void {
    if (!current_user()) { header('Location: login.php'); exit; }
    if (!empty($_SESSION['hrms_force_password_change']) && basename($_SERVER['PHP_SELF'])!=='change_password.php') { header('Location: change_password.php?first=1'); exit; }
}
function require_roles(array $roles): void {
    require_login(); $user=current_user();
    if (!$user || !in_array($user['role'], $roles, true)) { http_response_code(403); echo '<!doctype html><meta charset="utf-8"><title>Access denied</title><link rel="stylesheet" href="assets.css"><main><section class="panel"><h1>Access denied</h1><p>Your account does not have permission to open this page.</p><a class="btn" href="index.php">Return to dashboard</a></section></main>'; exit; }
}
function audit_event(mysqli $conn, string $action, string $entity='', ?int $entityId=null): void {
    try { $u=current_user(); $uid=$u['id']??null; $ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,45); $s=$conn->prepare('INSERT INTO audit_logs(user_id,action,entity,entity_id,ip_address) VALUES(?,?,?,?,?)'); $s->bind_param('issis',$uid,$action,$entity,$entityId,$ip); $s->execute(); $s->close(); } catch(Throwable $e) { error_log('HRMS audit: '.$e->getMessage()); }
}