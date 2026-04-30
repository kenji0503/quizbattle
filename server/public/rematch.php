<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/common/battle_common.php';
require_once __DIR__ . '/logs/logger.php';

$log = Logger::getInstance();
$log->debug('** rematch.php start');

$pdo = dbConnectPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$gid  = (int)($_GET['gid']  ?? $_SESSION['gid'] ?? 0);
$uid  = (int)($_SESSION['uid'] ?? 0);
$prev = (int)($_GET['prev'] ?? 0);

if ($gid <= 0 || $uid <= 0 || $prev <= 0) {
    http_response_code(400);
    exit('invalid gid/uid');
}

/* root は GET 優先→SESSION 次→なければ prev を初期 root とする */
$root = (int)($_GET['root'] ?? ($_SESSION['root_bid'][$gid] ?? 0));
if ($root <= 0) {
    $root = $prev;            // 1回目の「もう1回」で起点を確定
}
// セッションにも保持（join_battle 側でも参照できるように）
$_SESSION['root_bid'][$gid] = $root;

$newBid = $prev + 1;

try {
    // 同一グループで直列化（2人同時クリック対策）
    $lockName = sprintf('rematch_gid_%d', $gid);
    $pdo->beginTransaction();
    $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 5)");

    // まだ qb_battle に newBid が無ければ「予約」する（明示IDでINSERT）
    $exists = $pdo->prepare("SELECT 1 FROM qb_battle WHERE bid=? LIMIT 1");
    $exists->execute([$newBid]);

    if (!$exists->fetchColumn()) {
        // 以前のタイトルを流用（無ければデフォルト）
        $tst = $pdo->prepare("SELECT title FROM qb_battle WHERE bid=? LIMIT 1");
        $tst->execute([$prev]);
        $baseTitle = (string)($tst->fetchColumn() ?: 'バトル');

        // ※スキーマに合わせて列名を調整してください（is_temp 等があれば追記）
        $ins = $pdo->prepare("
            INSERT INTO qb_battle (bid, gid, title, description, make_uid, status, date)
            VALUES (?,   ?,   ?,     'クイズバトル', ?,        0,      CURDATE())
        ");
        $ins->execute([$newBid, $gid, $baseTitle . ' リマッチ', $uid]);
        $log->debug("[REMATCH] reserved qb_battle.bid={$newBid} (gid={$gid})");
    } else {
        $log->debug("[REMATCH] qb_battle.bid={$newBid} already exists; reuse");
    }

    // ▼▼▼ ここで scope を引き継ぐ：prev -> newBid へ複製 ▼▼▼
    // ユニーク制約(uq_bid_scope)に配慮して ON DUPLICATE KEY UPDATE を使用
    $copyScope = $pdo->prepare("
        INSERT INTO qb_battle_scope (bid, cate1, cate2, qid, weight)
        SELECT ?, s.cate1, s.cate2, s.qid, s.weight
          FROM qb_battle_scope s
         WHERE s.bid = ?
        ON DUPLICATE KEY UPDATE weight = VALUES(weight)
    ");
    $copyScope->execute([$newBid, $prev]);
    $copiedRows = $copyScope->rowCount(); // ※重複更新は2としてカウントされるDBもある点に注意
    $log->debug("[REMATCH] copied scope rows from bid={$prev} to bid={$newBid}, affected={$copiedRows}");

    // 新バトルの参加者として自分を登録
    $st = $pdo->prepare("
        INSERT INTO qb_battle_participants (bid,gid,uid,last_ping)
        VALUES (?,?,?,NOW())
        ON DUPLICATE KEY UPDATE last_ping=VALUES(last_ping)
    ");
    $st->execute([$newBid, $gid, $uid]);

    // セッション切り替え
    $_SESSION['bid'] = $newBid;

    // ロック解除 & コミット
    $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");
    $pdo->commit();

    // 例: rematch.php（新規 bid を作成したあと）
    $prev   = (int)($_GET['prev'] ?? $_GET['bid'] ?? 0); // もとのbid
    // リダイレクト時に root を必ず引き継ぐ
    header("Location: join_battle.php?gid={$gid}&bid={$newBid}&prev={$prev}&root={$root}");

    exit;
} catch (Throwable $e) {
    try {
        $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");
    } catch (\Throwable $e2) {
    }
    if ($pdo->inTransaction()) $pdo->rollBack();
    $log->debug('[REMATCH] error: ' . $e->getMessage());
    http_response_code(500);
    echo 'rematch error';
}
