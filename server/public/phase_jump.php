<?php
// クイズバトル用：解答表示／次の問題 へフェーズジャンプ（時刻ドリブン版）
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/logs/logger.php';
require_once __DIR__ . '/common/define.php';
require_once __DIR__ . '/common/battle_common.php';

$log = Logger::getInstance();
$log->debug('** phase_jump.php start');

$log->debug(sprintf(
    '[PHJUMP][REQ] bid=%s gid=%s action=%s now=%s',
    $_POST['bid'] ?? '-',
    $_POST['gid'] ?? '-',
    $_POST['action'] ?? '-',
    date('H:i:s.v')
));

$gid    = filter_input(INPUT_POST, 'gid', FILTER_VALIDATE_INT);
$bid    = filter_input(INPUT_POST, 'bid', FILTER_VALIDATE_INT);
$action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);

if (!$gid || !$bid || !$action) {
    $log->debug(sprintf(
        '[PHJUMP][BADREQ] bid=%s gid=%s action=%s POST=%s',
        var_export($bid, true),
        var_export($gid, true),
        var_export($action, true),
        json_encode($_POST, JSON_UNESCAPED_UNICODE)
    ));
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'bad request']);
    exit;
}


$log->debug(sprintf(
    '[PHJUMP][REQ] raw: method=%s uri=%s POST=%s',
    $_SERVER['REQUEST_METHOD'] ?? '-',
    $_SERVER['REQUEST_URI'] ?? '-',
    json_encode($_POST, JSON_UNESCAPED_UNICODE)
));

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = dbConnectPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 入力
    $gid    = filter_input(INPUT_POST, 'gid', FILTER_VALIDATE_INT);
    $bid    = filter_input(INPUT_POST, 'bid', FILTER_VALIDATE_INT);
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING); // 'reveal' or 'next'

    if (!$gid || !$bid || !in_array($action, ['reveal', 'next'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'bad request']);
        exit;
    }

    $nowMs = (int) floor(microtime(true) * 1000);

    $pdo->beginTransaction();

    // 時刻ドリブンの状態をロック取得
    $st = $pdo->prepare("SELECT bnum, q_start_at, reveal_at, switch_at FROM qb_battle_state WHERE bid=? AND gid=? FOR UPDATE");
    $st->execute([$bid, $gid]);
    $state = $st->fetch(PDO::FETCH_ASSOC);
    if (!$state) {
        throw new RuntimeException('state not found');
    }

    $bnum      = (int)$state['bnum'];
    $qStartAt  = (int)$state['q_start_at'];
    $revealAt  = (int)$state['reveal_at'];
    $switchAt  = (int)$state['switch_at'];

    // デフォルトの区間長は「現在の区間差分」を踏襲（設定が無くても破綻しない）
    $revealDelta = max(1000, $revealAt - $qStartAt);   // 出題→解答表示 まで
    $switchDelta = max(1000, $switchAt - $revealAt);   // 解答表示→スイッチ まで

    // 総問数（ラインナップ数）
    $stCnt = $pdo->prepare("SELECT COUNT(*) FROM qb_battle_lineup WHERE bid=?");
    $stCnt->execute([$bid]);
    $totalQ = (int)$stCnt->fetchColumn();

    // ログ用の before 情報
    $before = [
        'bnum' => $bnum,
        'q_start_at' => $qStartAt,
        'reveal_at' => $revealAt,
        'switch_at' => $switchAt
    ];

    if ($action === 'reveal') {
        // いまの問題の「解答表示」を即時化
        // reveal_at を今に、switch_at は少なくとも +1秒確保
        $newReveal = $nowMs;
        $newSwitch = max($switchAt, $nowMs + max(1000, $switchDelta));

        $up = $pdo->prepare("UPDATE qb_battle_state SET reveal_at=?, switch_at=? WHERE bid=? AND gid=?");
        $up->execute([$newReveal, $newSwitch, $bid, $gid]);
    } elseif ($action === 'next') {
        if ($totalQ <= 0) {
            throw new RuntimeException('lineup empty');
        }

        if ($bnum >= $totalQ) {

            // ★最終問→結果フェーズが確実に成立するように「過去時刻」＋ bnum を totalQ+1 へ
            $past = $nowMs - 1;

            // 最終問を終えている → 結果フェーズに落とすため、即スイッチ（watcher側でphase=3になる想定）
            $up = $pdo->prepare("UPDATE qb_battle_state SET q_start_at=?, reveal_at=?, switch_at=? WHERE bid=? AND gid=?");
            $up->execute([$nowMs, $nowMs, $nowMs, $bid, $gid]);
        } else {
            // 次の問題へ：bnumを+1、時刻をリセット
            $next = $bnum + 1;

            $newQ      = $nowMs;
            $newReveal = $nowMs + $revealDelta;
            $newSwitch = $newReveal + $switchDelta;

            $up = $pdo->prepare("UPDATE qb_battle_state SET bnum=?, q_start_at=?, reveal_at=?, switch_at=? WHERE bid=? AND gid=?");
            $up->execute([$next, $newQ, $newReveal, $newSwitch, $bid, $gid]);
        }
    } else {
        throw new RuntimeException('unknown action');
    }

    // 更新後の状態を取得してログ
    $st2 = $pdo->prepare("SELECT bnum, q_start_at, reveal_at, switch_at FROM qb_battle_state WHERE bid=? AND gid=?");
    $st2->execute([$bid, $gid]);
    $after = $st2->fetch(PDO::FETCH_ASSOC);

    $log->debug('[PHJUMP] bid=' . $bid . ' gid=' . $gid . ' action=' . $action . ' totalQ=' . $totalQ .
        ' before=' . json_encode($before, JSON_UNESCAPED_UNICODE) .
        ' after=' . json_encode($after, JSON_UNESCAPED_UNICODE) .
        ' now=' . $nowMs);

    $pdo->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if (!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $log->error('[phase_jump] ' . $e->getMessage());
    http_response_code(409);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
