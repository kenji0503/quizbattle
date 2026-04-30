<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/logs/logger.php';
require_once __DIR__ . '/common/define.php';
require_once __DIR__ . '/common/battle_common.php'; // dbConnectPDO() は上のconfigで繋がる前提
require_once __DIR__ . '/common/avatar.php';

$log = Logger::getInstance();
$log->debug('** get_lobby_status.php start'); // ★タイポ修正

try {
    $bid = (int)($_GET['bid'] ?? 0);
    $gid = (int)($_GET['gid'] ?? 0);
    if ($bid <= 0 || $gid <= 0) {
        echo json_encode(['participants' => [], 'count' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ★ 追加: ルート/前回 bid を拾う（join_battle と同じ方針） */
    $rootBid = (int)($_GET['root'] ?? ($_SESSION['root_bid'][$gid] ?? 0));
    $prevBid = (int)($_GET['prev'] ?? 0);

    $pdo = dbConnectPDO();

    // 一定時間が経っても消えないように、参加しているなら last_ping を更新（アクティブ扱いにする）
    if (
        isset($_SESSION['uid'], $_SESSION['gid'], $_SESSION['bid']) &&
        (int)$_SESSION['gid'] === (int)$gid &&
        (int)$_SESSION['bid'] === (int)$bid
    ) {
        $pdo->prepare("
        UPDATE qb_battle_participants
           SET last_ping = NOW()
         WHERE gid = :gid AND bid = :bid AND uid = :uid
    ")->execute([
            ':gid' => $gid,
            ':bid' => $bid,
            ':uid' => (int)$_SESSION['uid'],
        ]);
    }

    // 直近アクティブのみ（必要秒数は調整）
    $sql = "
  SELECT uid, name, avatar_type
    FROM qb_battle_participants
   WHERE bid = :bid
     AND gid = :gid
     AND last_ping > NOW() - INTERVAL 60 SECOND
   ORDER BY uid ASC
";
    $st = $pdo->prepare($sql);
    $st->execute([':bid' => $bid, ':gid' => $gid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    /* DBの avatar_type を必ず埋めてから返す */
    $avatars = [];
    $names   = [];
    foreach ($rows as $r) {
        $uid  = (int)$r['uid'];
        $name = (string)$r['name'];
        $type = (string)($r['avatar_type'] ?? '');

        if ($type === '' || $type === null) {
            // 未設定ならここで確定（ロック付きで重複回避）
            $type = ensureAvatarInParticipants($pdo, $gid, $bid, $uid, $name, $rootBid, $prevBid);
        }
        $avatars[] = ['name' => $name, 'type' => $type];
        $names[]   = $name;
    }
    $pcount = count($names);

    // --- 進行状態を返す（q_start_at を最優先で見る）---
    $nowMs   = (int)floor(microtime(true) * 1000);
    $started = false;          // 予約中は false
    $phase   = 0;              // 0:WAIT, 1:QUESTION, 2:ANSWER, 3:FINISHED
    $startAt = null;           // 予約中のみ返す

    // --- 最新 state は「bidだけ」で取得（ロビーは全端末で同じスケジュールを共有）---
    $ss = $pdo->prepare("
      SELECT bid,gid,bnum,q_start_at,reveal_at,switch_at,ts_ms
        FROM qb_battle_state
       WHERE bid=?
       ORDER BY ts_ms DESC
       LIMIT 1
    ");
    $ss->execute([$bid]);
    $row = $ss->fetch(PDO::FETCH_ASSOC);

    $nowMs = (int)floor(microtime(true) * 1000);
    $phase   = 0;
    $started = false;
    $startAt = null;
    $shouldGo = 0; // ← フロント用ヒント（1なら start.php へ）
    $qDisp = '-';
    $rvDisp = '-';
    $swDisp = '-';

    if ($row) {
        $q  = (int)$row['q_start_at'];
        $rv = (int)$row['reveal_at'];
        $sw = (int)$row['switch_at'];
        $qDisp  = fmt_ms($q);
        $rvDisp = fmt_ms($rv);
        $swDisp = fmt_ms($sw);

        // サーバ時刻でフェーズ導出
        if ($nowMs < $q) {
            $phase = 0;
            $startAt = $q;
        } elseif ($nowMs < $rv) {
            $phase = 1;
        } elseif ($nowMs < $sw) {
            $phase = 2;
        } else {
            $phase = 3;
        }

        // state が存在→開始準備/進行中
        $started = true;
        // ロビーでの遷移判定ヒント：QUESTION以降になったら即 start.php へ
        if ($phase >= 1) $shouldGo = 1;
    }

    // ログ強化：phase と start_at を必ず出す
    $log->debug(sprintf(
        "[LOBBY] bid=%d, gid(req)=%d, p=%d, started=%s, phase=%d, start_at=%s, now=%s, q=%s rv=%s sw=%s, should_go=%d",
        $bid,
        $gid,
        $pcount,
        $started ? 'T' : 'F',
        $phase,
        $startAt ? fmt_ms($startAt) : '-',
        fmt_ms($nowMs),
        $qDisp,
        $rvDisp,
        $swDisp,
        $shouldGo
    ));

    echo json_encode([
        'participants' => $names,          // ← 互換：名前だけ
        'avatars'      => $avatars,        // ← 新規：{name,type} の配列
        'count'        => (int)$pcount,
        'started'      => (bool)$started,
        'phase'        => (int)$phase,
        'start_at'     => $startAt ? (int)$startAt : null,
        'now'          => (int)$nowMs,
        'should_go'    => (int)$shouldGo,
        'q_start_at'   => $row ? (int)$row['q_start_at'] : null,
        'reveal_at'    => $row ? (int)$row['reveal_at']  : null,
        'switch_at'    => $row ? (int)$row['switch_at']  : null,
        'bid'          => $bid,
        'gid'          => $gid,
    ], JSON_UNESCAPED_UNICODE);


    exit;
} catch (Throwable $e) {
    $log->debug("[LOBBY][ERROR] " . $e->getMessage());
    echo json_encode(['participants' => [], 'count' => 0], JSON_UNESCAPED_UNICODE); // ★形を統一
    exit;
}
