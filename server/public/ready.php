<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/logs/logger.php';
require_once __DIR__ . '/common/define.php';
require_once __DIR__ . '/common/battle_common.php'; // dbConnectPDO() は上のconfigで繋がる前提
require_once __DIR__ . '/common/question_repository.php';

$log = Logger::getInstance();
$log->debug("** ready.php start");

$pdo = dbConnectPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$gid = (int)($_SESSION['gid'] ?? $_GET['gid'] ?? 0);
$bid = (int)($_SESSION['bid'] ?? $_GET['bid'] ?? 0);
if ($gid <= 0 || $bid <= 0) {
  http_response_code(400);
  exit('invalid gid/bid');
}
$_SESSION['gid'] = $gid;
$_SESSION['bid'] = $bid;

$scopeRows = $pdo->prepare("
  SELECT cate1, cate2, qid
    FROM qb_battle_scope
   WHERE bid = ?
   ORDER BY cate1, cate2, qid
");
$scopeRows->execute([$bid]);
$scopes = $scopeRows->fetchAll(PDO::FETCH_ASSOC);

foreach ($scopes as $scope) {
  $syncError = null;
  $saved = question_ensure_set_cached($pdo, (int)$scope['cate1'], (int)$scope['cate2'], (int)$scope['qid'], $syncError);
  if ($saved <= 0 && $syncError) {
    exit('問題取得に失敗しました: ' . htmlspecialchars($syncError, ENT_QUOTES, 'UTF-8'));
  }
}

/* 1) 参加者数（サーバ権威）。ロビーAPIと同じ last_ping 条件に揃える */
$st = $pdo->prepare("
  SELECT COUNT(*) FROM qb_battle_participants
   WHERE bid=? AND gid=? AND last_ping > NOW() - INTERVAL 60 SECOND
");
$st->execute([$bid, $gid]);
$pcount = (int)$st->fetchColumn();  // participants count
if ($pcount < 2) {
  exit('参加者が足りません');
}

/* 2) ラインナップ存在チェック（原子・一発で判定） */
/* 2) ラインナップ生成（この時点で作る／必要なら作り直す） */
$wantN   = 3;    // 出題数（UI未実装なら3）
$cate1In = isset($_GET['cate1']) ? trim($_GET['cate1']) : (isset($_POST['cate1']) ? trim($_POST['cate1']) : '');
$cate2In = isset($_GET['cate2']) ? trim($_GET['cate2']) : (isset($_POST['cate2']) ? trim($_POST['cate2']) : '');
$forceRegen = (isset($_GET['regen']) && $_GET['regen'] === '1') || (isset($_POST['regen']) && $_POST['regen'] === '1');

// いまのラインナップ状況を一発で確認
$stLine = $pdo->prepare("
  SELECT COUNT(*) AS cnt, MIN(order_no) AS mi, MAX(order_no) AS mx
    FROM qb_battle_lineup
   WHERE bid=?
");
$stLine->execute([$bid]);
$line   = $stLine->fetch(PDO::FETCH_ASSOC);
$lcount = (int)($line['cnt'] ?? 0);

// displayが空/NULLの行数を確認
$stMiss = $pdo->prepare("SELECT COUNT(*) FROM qb_battle_lineup WHERE bid=? AND (display IS NULL OR display='')");
$stMiss->execute([$bid]);
$missingDisplay = (int)$stMiss->fetchColumn();
/* qb_battle_scope テーブルの有無を確認（未作成でも落ちないように） */
$hasScopeTable = (bool)$pdo->query("
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qb_battle_scope'
")->fetchColumn();

/* スコープ有無（あれば優先して絞り込みに使う） */
$scopeCount = 0;
$hasScope   = false;
if ($hasScopeTable) {
  $stScopeCnt = $pdo->prepare('SELECT COUNT(*) FROM qb_battle_scope WHERE bid=?');
  $stScopeCnt->execute([$bid]);
  $scopeCount = (int)$stScopeCnt->fetchColumn();
  $hasScope   = $scopeCount > 0;
}

/* スコープが無い場合のみ、従来の cate1/cate2 絞りでフォールバック */
$wheres = [];
$paramsExtra = [];
 $wheres[] = "m.del = 0";
if (!$hasScope) {
  if ($cate1In !== '' && ctype_digit($cate1In)) {
    $wheres[] = "m.cate1 = ?";
    $paramsExtra[] = (int)$cate1In;
  }
  if ($cate2In !== '' && ctype_digit($cate2In)) {
    $wheres[] = "m.cate2 = ?";
    $paramsExtra[] = (int)$cate2In;
  }
}
$whereSql = $wheres ? ('WHERE ' . implode(' AND ', $wheres)) : '';

/* 「空 or 再生成指示 or display欠落あり」のときだけ作る */
if ($lcount === 0 || $forceRegen || $missingDisplay > 0) {

  // 同時押し対策：丸ごと入れ替え idempotent
  $pdo->beginTransaction();
  $pdo->prepare("DELETE FROM qb_battle_lineup WHERE bid=?")->execute([$bid]);
  $pdo->exec("SET @o := 0");

  // スコープがあれば JOIN で候補を絞る。抽選は常に均等ランダム（ORDER BY RAND()）
  $joinScope = ($hasScope)
    ? 'JOIN qb_battle_scope s
           ON s.bid = ?
          AND s.cate1 = m.cate1
          AND s.cate2 = m.cate2
          AND s.qid   = m.qid'
    : '';

  $sql = "
    INSERT INTO qb_battle_lineup (bid, order_no, cate1, cate2, id, num, display)
    SELECT
      ?,                                 -- ← lineup.bid（INSERT用）
      (@o := @o + 1) AS order_no,
      m.cate1,
      m.cate2,
      m.qid,
      m.qnum,
      CONCAT('「', COALESCE(ht.title, m.qid),' 」の問題') AS display
    FROM qb_question_bank AS m
    {$joinScope}
    LEFT JOIN qb_question_category AS hg
      ON hg.cate1 = m.cate1 AND hg.cate2 = 0 AND hg.qid = 0
    LEFT JOIN qb_question_category AS ht
      ON ht.cate1 = m.cate1 AND ht.cate2 = m.cate2 AND ht.qid = m.qid
    {$whereSql}
    ORDER BY RAND()
    LIMIT ?
  ";

  // パラメータ並び：
  //  1) INSERT先bid
  //  2) （スコープがある場合のみ）JOIN用 bid
  //  3) （スコープが無い場合のみ）cate1/cate2 絞りの値
  //  4) LIMIT
  $paramList = [$bid];
  if ($hasScope) $paramList[] = $bid;
  $paramList = array_merge($paramList, $paramsExtra, [$wantN]);

  $ins = $pdo->prepare($sql);
  $ins->execute($paramList);

  $pdo->commit();

  // 作れたか再確認
  $stLine->execute([$bid]);
  $line   = $stLine->fetch(PDO::FETCH_ASSOC);
  $lcount = (int)($line['cnt'] ?? 0);

  if ($lcount === 0) {
    $log->debug("lineup not found: bid={$bid} (scope={$scopeCount}, cate1={$cate1In}, cate2={$cate2In}, n={$wantN})");
    exit("lineup not found for bid={$bid} (no questions matched filters)");
  }
}

/* 常に 1..N へリナンバー（display も保持） */
$pdo->beginTransaction();
$pdo->prepare("
    CREATE TEMPORARY TABLE tmp AS
      SELECT cate1,cate2,id,num,display
        FROM qb_battle_lineup
       WHERE bid=?
       ORDER BY order_no
")->execute([$bid]);
$pdo->prepare("DELETE FROM qb_battle_lineup WHERE bid=?")->execute([$bid]);
$pdo->exec("SET @o:=0");
$pdo->prepare("
  INSERT INTO qb_battle_lineup (bid,order_no,cate1,cate2,id,num,display)
  SELECT ?, (@o:=@o+1), cate1,cate2,id,num,display
    FROM tmp
")->execute([$bid]);
$pdo->commit();

/* デバッグ：確認ログ */
$mx = $pdo->prepare("SELECT COUNT(*) c, MIN(order_no) mi, MAX(order_no) mx FROM qb_battle_lineup WHERE bid=?");
$mx->execute([$bid]);
$a = $mx->fetch(PDO::FETCH_ASSOC);
$log->debug(sprintf(
  "[LINEUP][RENABLED] bid=%d count=%d range=%d..%d",
  $bid,
  (int)$a['c'],
  (int)$a['mi'],
  (int)$a['mx']
));

$log->debug("lineup ready: bid={$bid}, gid={$gid}, count={$lcount}");

/* 3) state を seed（初期1行を作成/更新） */
$QUESTION_MS = defined('QB_QUESTION_MS') ? QB_QUESTION_MS : 8000;
$ANSWER_MS   = defined('QB_ANSWER_MS')   ? QB_ANSWER_MS   : 8000;
$REVEAL_PENDING_MS = defined('BATTLE_REVEAL_PENDING_MS') ? BATTLE_REVEAL_PENDING_MS : 1000;
$nowMs       = (int)floor(microtime(true) * 1000);
$delayMs     = 3000;
$stState = $pdo->prepare("
  SELECT bid, gid, bnum, phase, q_start_at, reveal_at, switch_at, ts_ms
    FROM qb_battle_state
   WHERE bid = :bid AND gid = :gid
   LIMIT 1
");
$stState->execute([':bid' => $bid, ':gid' => $gid]);
$stateRow = $stState->fetch(PDO::FETCH_ASSOC) ?: null;

if ($stateRow) {
  $existingQStart = (int)$stateRow['q_start_at'];
  $existingReveal = (int)$stateRow['reveal_at'];
  $existingSwitch = (int)$stateRow['switch_at'];
  $derivedPhase = battle_derive_phase($nowMs, $existingQStart, $existingReveal, $existingSwitch);

  if ($derivedPhase === BATTLE_PHASE_WAIT && $existingQStart > $nowMs) {
    $q_start_at = $existingQStart;
    $reveal_at = $existingReveal;
    $switch_at = $existingSwitch;
    $log->debug(sprintf(
      '[COUNTDOWN_REUSED] bid=%d gid=%d now=%s q_start_at=%s reveal_at=%s switch_at=%s',
      $bid,
      $gid,
      fmt_ms($nowMs),
      fmt_ms($q_start_at),
      fmt_ms($reveal_at),
      fmt_ms($switch_at)
    ));
  } elseif ($derivedPhase >= BATTLE_PHASE_QUESTION && $derivedPhase < BATTLE_PHASE_FINISHED) {
    header("Location: start.php?gid={$gid}&bid={$bid}");
    exit;
  } elseif ($derivedPhase >= BATTLE_PHASE_FINISHED) {
    header("Location: result.php?gid={$gid}&bid={$bid}&notice=ended");
    exit;
  }
}

if (!isset($q_start_at)) {
  $q_start_at  = $nowMs + $delayMs;
  $reveal_at   = $q_start_at + $QUESTION_MS + $REVEAL_PENDING_MS;
  $switch_at   = $reveal_at + $ANSWER_MS;
  $ts_ms       = $nowMs;

  $ins = $pdo->prepare("
     INSERT INTO qb_battle_state
       (bid,gid,bnum,phase,q_start_at,reveal_at,switch_at,ts_ms)
     VALUES (?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
       bnum=VALUES(bnum), phase=VALUES(phase),
       q_start_at=VALUES(q_start_at), reveal_at=VALUES(reveal_at),
       switch_at=VALUES(switch_at), ts_ms=VALUES(ts_ms)
   ");
  $ins->execute([$bid, $gid, 1, 0, $q_start_at, $reveal_at, $switch_at, $ts_ms]);
  battle_update_lineup_schedule($pdo, $bid, 1, $q_start_at, $reveal_at, $switch_at);

  $log->debug(sprintf(
    '[COUNTDOWN_SEEDED] bid=%d gid=%d now=%s q_start_at=%s reveal_at=%s switch_at=%s (delayMs=%d, Q=%d, A=%d)',
    $bid,
    $gid,
    fmt_ms($nowMs),
    fmt_ms($q_start_at),
    fmt_ms($reveal_at),
    fmt_ms($switch_at),
    $delayMs,
    $QUESTION_MS,
    $ANSWER_MS
  ));

  battle_ws_publish(
    ["battle:{$bid}:{$gid}", "lobby:{$bid}:{$gid}"],
    'battle.state',
    [
      'bid' => (int)$bid,
      'gid' => (int)$gid,
      'bnum' => 1,
      'phase' => BATTLE_PHASE_WAIT,
      'q_start_at' => (int)$q_start_at,
      'reveal_at' => (int)$reveal_at,
      'switch_at' => (int)$switch_at,
      'now' => (int)$nowMs,
    ]
  );
}

?>
<!doctype html>
<html lang="ja">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>バトル開始準備</title>
  <link rel="stylesheet" href="css/battle.css">
  <style>
    .count {
      font-size: 4rem;
      font-weight: 800;
      text-align: center;
      margin: 32px 0;
    }
  </style>
</head>

<body>
  <h1 style="text-align:center">バトル開始</h1>
  <h2 style="text-align:center">問題を出題します</h2>
  <div id="cd" class="count">3</div>

  <script>
    const startAtMs = <?= (int)$q_start_at ?>;
    const bid = <?= (int)$bid ?>;
    const el = document.getElementById('cd');

    const render = () => {
      const remainMs = startAtMs - Date.now();
      const remainSec = Math.ceil(remainMs / 1000);
      if (remainSec > 0) {
        el.textContent = String(remainSec);
        return;
      }
      clearInterval(iv);
      location.href = `start.php?bid=${bid}`;
    };

    render();
    const iv = setInterval(render, 100);
  </script>
</body>

</html>
