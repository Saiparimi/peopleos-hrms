<?php
require_once __DIR__.'/auth.php';
if ($_SERVER['REQUEST_METHOD']!=='POST') { header('Location: index.php'); exit; }
csrf_verify(); if (current_user()) audit_event($conn,'logout','user',(int)current_user()['id']); $_SESSION=[]; if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']); } session_destroy(); header('Location: login.php?loggedout=1'); exit;
