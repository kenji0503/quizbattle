<?php
// /user/auth.php など。login.php では読み込まないでください
if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../logs/config.php';
require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/../common/battle_common.php';

$log = Logger::getInstance();
$log->debug('** auth.php start');

define('SESSION_TIMEOUT', 30 * 60); // 30分

function redirect_to_login_and_exit()
{
	header('Location: ' . BASE_URL . 'user/login.php?expired=1');
	exit();
}

// --- 1) 最低限のセッション存在チェック（uid / gid / LAST_ACTIVITY）
$uid = $_SESSION['uid'] ?? null;
$gid = $_SESSION['gid'] ?? null;
$last = $_SESSION['LAST_ACTIVITY'] ?? null;

if (!$uid || !$last) {
	$log->debug('*** error auth.php 1');
	session_unset();
	session_destroy();
	redirect_to_login_and_exit();
}

// --- 2) タイムアウトチェック
if ((time() - (int)$last) > SESSION_TIMEOUT) {
	$log->debug('*** error  auth.php 2');
	session_unset();
	session_destroy();
	redirect_to_login_and_exit();
}

// --- 3) DBトークンと session_id() の一致確認（sc_userのみ）
try {
	$pdo = dbConnectPDO();
	$stmt = $pdo->prepare("SELECT session_token FROM sc_user WHERE uid = ?");
	$stmt->execute([(int)$uid]);
	$dbToken = $stmt->fetchColumn();
	$sid = session_id();

	if (!$dbToken || !hash_equals($dbToken, $sid)) {
		$log->debug('*** error  auth.php 3');
		// 他端末ログインやセッション破棄後のアクセス
		session_unset();
		session_destroy();
		redirect_to_login_and_exit();
	}
} catch (Throwable $e) {
	$log->debug('*** error  auth.php 4');
	// DBエラー時は安全側でログインやり直し
	$log->error('auth.php DB error: ' . $e->getMessage());
	session_unset();
	session_destroy();
	redirect_to_login_and_exit();
}

// --- 4) アクセス更新
$_SESSION['LAST_ACTIVITY'] = time();

$log->debug('認証OK: uid=' . $uid . ' gid=' . $gid . ' name=' . ($_SESSION['name'] ?? '不明'));

$log->debug('** auth.php end');
