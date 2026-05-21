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

    $stateStmt = $pdo->prepare("
        SELECT bnum, phase, q_start_at, reveal_at, switch_at
          FROM qb_battle_state
         WHERE bid = :bid AND gid = :gid
         LIMIT 1
    ");
    $stateStmt->execute([':bid' => $bid, ':gid' => $gid]);
    $state = $stateStmt->fetch(PDO::FETCH_ASSOC);
    if (!$state) {
        api_json_send(['error' => 'state missing']);
    }

    $lineupStmt = $pdo->prepare("
        SELECT cate1, cate2, id, num
          FROM qb_battle_lineup
         WHERE bid = :bid AND order_no = :bnum
         LIMIT 1
    ");
    $lineupStmt->execute([':bid' => $bid, ':bnum' => (int)$state['bnum']]);
    $currentLineup = $lineupStmt->fetch(PDO::FETCH_ASSOC);

    $nowMs = (int)floor(microtime(true) * 1000);
    $revealPendingMs = defined('BATTLE_REVEAL_PENDING_MS') ? BATTLE_REVEAL_PENDING_MS : 1000;
    $answerCloseAt = max((int)$state['q_start_at'], (int)$state['reveal_at'] - $revealPendingMs);
    $isCurrentQuestion =
        $currentLineup &&
        (int)$currentLineup['cate1'] === $cate1 &&
        (int)$currentLineup['cate2'] === $cate2 &&
        (int)$currentLineup['id'] === $qid &&
        (int)$currentLineup['num'] === $num;

    $isAnswerWindow =
        $nowMs >= (int)$state['q_start_at'] &&
        $nowMs < $answerCloseAt &&
        (int)$state['phase'] !== 3;

    if (!$isCurrentQuestion || !$isAnswerWindow) {
        api_json_send([
            'error' => 'answer_closed',
            'now_ms' => $nowMs,
            'q_start_at' => (int)$state['q_start_at'],
            'reveal_at' => (int)$state['reveal_at'],
            'answer_close_at' => $answerCloseAt,
            'switch_at' => (int)$state['switch_at'],
        ]);
    }

    $pingStmt = $pdo->prepare("
        UPDATE qb_battle_participants
           SET last_ping = NOW()
         WHERE bid = :bid
           AND gid = :gid
           AND uid = :uid
    ");
    $pingStmt->execute([':bid' => $bid, ':gid' => $gid, ':uid' => $uid]);

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

    $stAnswered = $pdo->prepare("
        SELECT COUNT(DISTINCT uid)
          FROM qb_buzzes
         WHERE bid=:bid AND gid=:gid AND cate1=:c1 AND cate2=:c2 AND id=:qid AND num=:num
    ");
    $stAnswered->execute([':bid' => $bid, ':gid' => $gid, ':c1' => $cate1, ':c2' => $cate2, ':qid' => $qid, ':num' => $num]);
    $answeredPlayers = (int)$stAnswered->fetchColumn();

    $stPlayers = $pdo->prepare("
        SELECT COUNT(DISTINCT uid)
          FROM qb_battle_participants
         WHERE bid=:bid AND gid=:gid AND last_ping > NOW() - INTERVAL 60 SECOND
    ");
    $stPlayers->execute([':bid' => $bid, ':gid' => $gid]);
    $totalPlayers = (int)$stPlayers->fetchColumn();
    $allAnswered = ($totalPlayers > 0 && $answeredPlayers >= $totalPlayers) ? 1 : 0;

    battle_ws_publish(
        ["battle:{$bid}:{$gid}"],
        'battle.buzz',
        [
            'bid' => $bid,
            'gid' => $gid,
            'cate1' => $cate1,
            'cate2' => $cate2,
            'id' => $qid,
            'num' => $num,
            'answered_players' => $answeredPlayers,
            'total_players' => $totalPlayers,
            'all_answered' => $allAnswered,
        ]
    );

    api_json_send([
        'position'    => $position,
        'selected'    => $sel,
        'answered_at' => $myts ? date('H:i:s', strtotime($myts)) : date('H:i:s'),
        'duplicate'   => $already,
        'answered_players' => $answeredPlayers,
        'total_players' => $totalPlayers,
        'all_answered' => $allAnswered,
    ]);
} catch (Throwable $e) {
    $log->debug('submit_buzz exception: ' . $e->getMessage());
    api_json_send(['error' => 'server error']);
}
