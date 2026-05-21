<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/common/battle_common.php';
require_once __DIR__ . '/logs/logger.php';

$log = Logger::getInstance();
$log->debug('** rematch.php start');

function renderTimeoutPage(int $gid, int $prev): never
{
    $recruitHref = 'rematch.php?gid=' . max(0, $gid) . '&prev=' . max(0, $prev) . '&action=recruit';
    $topHref = 'index.php';
    http_response_code(200);
    ?>
    <!doctype html>
    <html lang="ja">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>タイムアウト</title>
        <style>
            :root {
                --bg1: #181a3a;
                --bg2: #2b1847;
                --panel: rgba(22, 28, 58, .82);
                --stroke: rgba(255, 255, 255, .14);
                --text: #f5f7ff;
                --sub: rgba(245, 247, 255, .78);
                --accent: #ffb24d;
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                display: grid;
                place-items: center;
                padding: 24px;
                color: var(--text);
                font-family: system-ui, -apple-system, "Segoe UI", "Hiragino Kaku Gothic ProN", "Meiryo", sans-serif;
                background:
                    radial-gradient(900px 520px at 20% 0%, rgba(104, 93, 255, .24), transparent 60%),
                    radial-gradient(720px 480px at 100% 20%, rgba(0, 186, 255, .18), transparent 58%),
                    linear-gradient(180deg, var(--bg1), var(--bg2));
            }

            .panel {
                width: min(100%, 720px);
                padding: 28px 24px;
                border-radius: 20px;
                background: var(--panel);
                border: 1px solid var(--stroke);
                box-shadow: 0 18px 48px rgba(0, 0, 0, .34);
            }

            h1 {
                margin: 0 0 16px;
                font-size: clamp(24px, 4vw, 34px);
                color: var(--accent);
            }

            p {
                margin: 0;
                font-size: 16px;
                line-height: 1.7;
                color: var(--sub);
            }

            .actions {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                margin-top: 22px;
            }

            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 46px;
                padding: 10px 18px;
                border-radius: 999px;
                text-decoration: none;
                font-weight: 800;
            }

            .btn-primary {
                background: linear-gradient(135deg, #ffd45a, #ffb24d);
                color: #281700;
            }

            .btn-secondary {
                border: 1px solid rgba(255, 255, 255, .18);
                background: rgba(255, 255, 255, .06);
                color: var(--text);
            }
        </style>
    </head>

    <body>
        <main class="panel">
            <h1>タイムアウトしました</h1>
            <p>時間が経過したためタイムアウトしました。<br>再び同じタイトルでバトルを行いたい場合は以下からメンバの募集を行って下さい。</p>
            <div class="actions">
                <a class="btn btn-primary" href="<?= htmlspecialchars($recruitHref, ENT_QUOTES, 'UTF-8') ?>">募集画面を開く</a>
                <a class="btn btn-secondary" href="<?= htmlspecialchars($topHref, ENT_QUOTES, 'UTF-8') ?>">トップへ戻る</a>
            </div>
        </main>
    </body>

    </html>
    <?php
    exit;
}

function ensureRecruitBattleFromPrevious(PDO $pdo, int $gid, int $prev, int $makeUid = 0): int
{
    $lockName = sprintf('rematch_gid_%d', $gid);
    $pdo->beginTransaction();
    try {
        $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 5)");

        $tst = $pdo->prepare("SELECT title FROM qb_battle WHERE bid=? LIMIT 1");
        $tst->execute([$prev]);
        $baseTitle = (string)($tst->fetchColumn() ?: 'バトル');

        for ($candidate = $prev + 1; $candidate < $prev + 100; $candidate++) {
            $battleSt = $pdo->prepare("SELECT gid FROM qb_battle WHERE bid=? LIMIT 1");
            $battleSt->execute([$candidate]);
            $existingGid = $battleSt->fetchColumn();

            if ($existingGid === false) {
                $ins = $pdo->prepare("
                    INSERT INTO qb_battle (bid, gid, title, description, make_uid, status, date)
                    VALUES (?, ?, ?, 'クイズバトル', ?, 0, CURDATE())
                ");
                $ins->execute([$candidate, $gid, $baseTitle . ' リマッチ', $makeUid]);

                $copyScope = $pdo->prepare("
                    INSERT INTO qb_battle_scope (bid, cate1, cate2, qid, weight)
                    SELECT ?, s.cate1, s.cate2, s.qid, s.weight
                      FROM qb_battle_scope s
                     WHERE s.bid = ?
                    ON DUPLICATE KEY UPDATE weight = VALUES(weight)
                ");
                $copyScope->execute([$candidate, $prev]);

                $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");
                $pdo->commit();
                return $candidate;
            }

            if ((int)$existingGid !== $gid) {
                continue;
            }

            $stateSt = $pdo->prepare("
                SELECT phase
                  FROM qb_battle_state
                 WHERE gid = ? AND bid = ?
                 ORDER BY ts_ms DESC
                 LIMIT 1
            ");
            $stateSt->execute([$gid, $candidate]);
            $phase = $stateSt->fetchColumn();
            if ($phase === false || (int)$phase < 3) {
                $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");
                $pdo->commit();
                return $candidate;
            }
        }

        throw new RuntimeException('recruit battle allocation failed');
    } catch (Throwable $e) {
        try {
            $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");
        } catch (\Throwable $e2) {
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

$pdo = dbConnectPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$gid  = (int)($_GET['gid']  ?? $_SESSION['gid'] ?? 0);
$uid  = (int)($_SESSION['uid'] ?? 0);
$prev = (int)($_GET['prev'] ?? 0);
$action = (string)($_GET['action'] ?? '');
$name = trim((string)($_SESSION['name'] ?? ''));

if ($gid <= 0 || $prev <= 0) {
    http_response_code(400);
    exit('invalid gid/uid');
}

if ($uid <= 0) {
    if ($action === 'recruit') {
        try {
            $recruitBid = ensureRecruitBattleFromPrevious($pdo, $gid, $prev, 0);
            header("Location: battle.php?mode=show&bid={$recruitBid}");
            exit;
        } catch (Throwable $e) {
            $log->debug('[REMATCH][TIMEOUT_RECRUIT] error: ' . $e->getMessage());
            http_response_code(500);
            exit('recruit error');
        }
    }
    renderTimeoutPage($gid, $prev);
}

if ($name === '') {
    $nameSt = $pdo->prepare("
        SELECT name
          FROM qb_battle_participants
         WHERE gid = :gid
           AND bid = :bid
           AND uid = :uid
         LIMIT 1
    ");
    $nameSt->execute([
        ':gid' => $gid,
        ':bid' => $prev,
        ':uid' => $uid,
    ]);
    $name = trim((string)($nameSt->fetchColumn() ?: ''));
}

if ($name === '') {
    $name = 'Player#' . $uid;
}

$_SESSION['name'] = $name;

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
        INSERT INTO qb_battle_participants (bid, gid, uid, name, joined_at, last_ping)
        VALUES (?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            last_ping = VALUES(last_ping)
    ");
    $st->execute([$newBid, $gid, $uid, $name]);

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
