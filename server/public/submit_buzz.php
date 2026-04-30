<?php
require_once __DIR__ . '/api_bootstrap.php';  // ★ 追加：一番最初に

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/logs/logger.php';
require_once __DIR__ . '/common/define.php';
require_once __DIR__ . '/common/battle_common.php'; // dbConnectPDO() は上のconfigで繋がる前提

$log = Logger::getInstance();
$log->debug('** submit_buzz.php (qb) start');

try {
    $pdo = dbConnectPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // セッション優先（POSTはフォールバック）
    $uid = (int)($_SESSION['uid'] ?? ($_POST['uid'] ?? 0));
    $gid = (int)($_SESSION['gid'] ?? ($_POST['gid'] ?? 0));
    $bid = (int)($_SESSION['bid'] ?? ($_POST['bid'] ?? 0));
    if ($uid <= 0 || $gid <= 0 || $bid <= 0) {
        api_json_send(['error' => 'invalid session']);
    }

    // 入力
    $cate1 = (int)($_POST['cate1'] ?? 0);
    $cate2 = (int)($_POST['cate2'] ?? 0);
    $qid   = (int)($_POST['id']    ?? 0);
    $num   = (int)($_POST['num']   ?? 0);
    $sel   = strtoupper(trim((string)($_POST['selected'] ?? '')));
    if ($cate1 === 0 || $qid === 0 || !in_array($sel, ['A', 'B', 'C', 'D'], true)) {
        api_json_send(['error' => 'invalid parameters']);
    }

    // 既回答チェック
    $chk = $pdo->prepare("
        SELECT 1 FROM qb_buzzes
         WHERE bid=:bid AND gid=:gid AND cate1=:c1 AND cate2=:c2 AND id=:qid AND num=:num AND uid=:uid
         LIMIT 1
    ");
    $chk->execute([':bid' => $bid, ':gid' => $gid, ':c1' => $cate1, ':c2' => $cate2, ':qid' => $qid, ':num' => $num, ':uid' => $uid]);
    $already = (bool)$chk->fetchColumn();

    if (!$already) {
        $ins = $pdo->prepare("
            INSERT INTO qb_buzzes
              (bid,gid,cate1,cate2,id,num,uid,sentaku,buzzed_at)
            VALUES
              (:bid,:gid,:c1,:c2,:qid,:num,:uid,:sel,NOW())
        ");
        $ins->execute([
            ':bid' => $bid,
            ':gid' => $gid,
            ':c1' => $cate1,
            ':c2' => $cate2,
            ':qid' => $qid,
            ':num' => $num,
            ':uid' => $uid,
            ':sel' => $sel
        ]);
    }

    // 自分の回答時刻
    $myts = null;
    $mytsStmt = $pdo->prepare("
        SELECT buzzed_at FROM qb_buzzes
         WHERE bid=:bid AND gid=:gid AND cate1=:c1 AND cate2=:c2 AND id=:qid AND num=:num AND uid=:uid
         LIMIT 1
    ");
    $mytsStmt->execute([':bid' => $bid, ':gid' => $gid, ':c1' => $cate1, ':c2' => $cate2, ':qid' => $qid, ':num' => $num, ':uid' => $uid]);
    $myts = $mytsStmt->fetchColumn();

    // 順位
    $position = null;
    if ($myts) {
        $posStmt = $pdo->prepare("
            SELECT COUNT(*) FROM qb_buzzes
             WHERE bid=:bid AND gid=:gid AND cate1=:c1 AND cate2=:c2 AND id=:qid AND num=:num
               AND buzzed_at <= :ts
        ");
        $posStmt->execute([':bid' => $bid, ':gid' => $gid, ':c1' => $cate1, ':c2' => $cate2, ':qid' => $qid, ':num' => $num, ':ts' => $myts]);
        $position = (int)$posStmt->fetchColumn();
    }

    api_json_send([
        'position'    => $position,
        'selected'    => $sel,
        'answered_at' => $myts ? date('H:i:s', strtotime($myts)) : date('H:i:s'),
        'duplicate'   => $already
    ]);
} catch (Throwable $e) {
    $log->debug('submit_buzz exception: ' . $e->getMessage());
    api_json_send(['error' => 'server error']);
}
