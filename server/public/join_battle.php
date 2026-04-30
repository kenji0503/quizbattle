<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/logs/logger.php';
require_once __DIR__ . '/common/define.php';
require_once __DIR__ . '/common/battle_common.php';
require_once __DIR__ . '/common/avatar.php';

$log = Logger::getInstance();
$log->debug('** join_battle.php start');

$gid = filter_input(INPUT_GET, 'gid', FILTER_VALIDATE_INT);
$bid = filter_input(INPUT_GET, 'bid', FILTER_VALIDATE_INT);
$prevBid = (int)($_GET['prev'] ?? 0);

/* rootBid をGET or セッションから。受け取ったらセッション側に保存して維持 */
$rootBid = (int)($_GET['root'] ?? ($_SESSION['root_bid'][$gid] ?? 0));
if ($rootBid > 0) {
    $_SESSION['root_bid'][$gid] = $rootBid;
}

if (!$gid || !$bid) {
    $log->warning('Invalid access to join_battle.php: missing or invalid gid/bid');
    http_response_code(400);
    echo '不正なアクセスです。';
    exit;
}

// すでにセッションや必要な require が完了した後に追加でこれを記述
$ogTitle = "クイズバトルに参加しよう！";
$ogDesc  = "友達とリアルタイムでクイズ対戦！誰が最強か決めよう！";
$ogImage = abs_url('images/battle_link.png'); // 絶対URLを取得する関数（後述）
$ogUrl   = abs_url("join_battle.php?gid=$gid&bid=$bid");

$pdo = dbConnectPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// バトル終了なら結果へ
$st = $pdo->prepare('SELECT phase FROM qb_battle_state WHERE gid=:gid AND bid=:bid LIMIT 1');
$st->execute([':gid' => $gid, ':bid' => $bid]);
$phase = (int)$st->fetchColumn();
if ($phase >= 3) {
    header("Location: result.php?gid={$gid}&bid={$bid}&notice=ended");
    exit;
}

// 参加済み判定
$alreadyJoined = (
    isset($_SESSION['uid'], $_SESSION['gid'], $_SESSION['bid'], $_SESSION['name']) &&
    (int)$_SESSION['gid'] === (int)$gid &&
    (int)$_SESSION['bid'] === (int)$bid
);
$currentName = $alreadyJoined ? (string)$_SESSION['name'] : null;

// 参加記録UPSERT
function upsertParticipant(PDO $pdo, int $bid, int $gid, int $uid, string $name): void
{
    $sql = "INSERT INTO qb_battle_participants (bid,gid,uid,name,joined_at,last_ping)
            VALUES (?,?,?,?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE name=VALUES(name), last_ping=NOW()";
    $pdo->prepare($sql)->execute([$bid, $gid, $uid, $name]);
}

function generateAnonymousUid(): int
{
    try {
        return random_int(100000000, 2147483647);
    } catch (\Throwable $e) {
        return (int)(microtime(true) * 1000) % 2147483647;
    }
}

// 既ログインで同gidなら bid同期
if (isset($_SESSION['uid'], $_SESSION['gid']) && (int)$_SESSION['gid'] === (int)$gid) {
    $_SESSION['bid'] = (int)$bid;
}

$error = '';

// POST: 名前登録
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyJoined) {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $error = "名前を入力してください。";
    } else {
        // 同じバトル内での重複名を防ぐ
        $st = $pdo->prepare("
            SELECT uid
              FROM qb_battle_participants
             WHERE gid = :gid AND bid = :bid AND name = :name
             LIMIT 1
        ");
        $st->execute([':gid' => $gid, ':bid' => $bid, ':name' => $name]);
        $exists = $st->fetchColumn();

        if ($exists && (int)$exists !== (int)($_SESSION['uid'] ?? 0)) {
            $error = "この名前はすでに使用されています。別の名前を入力してください。";
        } else {
            session_regenerate_id(true);
            $uid = (int)($_SESSION['uid'] ?? 0);
            if ($uid <= 0) {
                $uid = generateAnonymousUid();
            }
            $_SESSION['uid']  = $uid;
            $_SESSION['gid']  = $gid;
            $_SESSION['bid']  = $bid;
            $_SESSION['name'] = $name;
            $_SESSION['LAST_ACTIVITY'] = time();

            upsertParticipant($pdo, $bid, $gid, $uid, $name);

            // ★ ここで自分の avatar を確定
            ensureAvatarInParticipants($pdo, $gid, $bid, $uid, $name, $rootBid, $prevBid);

            $log->debug("Anonymous participant registered: uid=$uid, gid=$gid, bid=$bid, name=$name");
            header("Location: join_battle.php?gid={$gid}&bid={$bid}");
            exit;
        }
    }
}

// 既参加で表示時もpingを更新 + avatar 確定
if ($alreadyJoined) {
    upsertParticipant($pdo, (int)$bid, (int)$gid, (int)$_SESSION['uid'], (string)$_SESSION['name']);
    ensureAvatarInParticipants($pdo, $gid, $bid, (int)$_SESSION['uid'], (string)$_SESSION['name'], $rootBid, $prevBid);
}

/* --- 初期描画データを DB から取得（avatar_typeを必ず埋めた上で返す） --- */
$stList = $pdo->prepare("
    SELECT uid, name, avatar_type
      FROM qb_battle_participants
     WHERE bid = :bid AND gid = :gid
     ORDER BY joined_at ASC
");
$stList->execute([':bid' => $bid, ':gid' => $gid]);

$participants   = [];
$initialAvatars = [];
while ($r = $stList->fetch(PDO::FETCH_ASSOC)) {
    $participants[] = $r['name'];
    $type = $r['avatar_type'];
    if ($type) {
        $initialAvatars[] = ['name' => $r['name'], 'type' => $type]; // ★ type がある人だけ初期描画
    }
}

// ... 既存の $participants / $initialAvatars 生成の直後あたりに追記
$stThemes = $pdo->prepare("
    SELECT s.cate1, s.cate2, s.qid,
           (SELECT title FROM qb_question_category
             WHERE cate1=s.cate1 AND cate2=0        AND qid=0   LIMIT 1) AS c1_title,
           (SELECT title FROM qb_question_category
             WHERE cate1=s.cate1 AND cate2=s.cate2  AND qid=0   LIMIT 1) AS c2_title,
           (SELECT title FROM qb_question_category
             WHERE cate1=s.cate1 AND cate2=s.cate2  AND qid=s.qid LIMIT 1) AS theme_title
      FROM qb_battle_scope s
     WHERE s.bid = :bid
     ORDER BY s.cate1, s.cate2, s.qid
");
$stThemes->execute([':bid' => $bid]);
$themes = $stThemes->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Language" content="ja" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>クイズバトル待合室</title>
    <link rel="stylesheet" href="css/sprite.css">
    <script src="js/sprite_runtime.js"></script>
    <style>
        :root {
            --space-bg: #060814;
            --space-ink: #e6eaf2;
            --space-dim: #9fb0c7;
            --card-bg: rgba(255, 255, 255, .06);
            --card-stroke: rgba(255, 255, 255, .12);
            --accent: #69f0ff;
            --me: #7effa1;
            --ampX: 24px;
            --ampY: 12px;
        }

        html,
        body {
            height: 100%
        }

        body.space {
            margin: 0;
            color: var(--space-ink);
            background: var(--space-bg);
            font-family: system-ui, -apple-system, "Segoe UI", "Hiragino Kaku Gothic ProN", "Meiryo", Roboto, "Noto Sans JP", sans-serif;
            overflow-x: hidden;
        }

        .space-header {
            position: sticky;
            top: 0;
            z-index: 6;
            background: linear-gradient(90deg, #181a3a, #2a1a47 40%, #0b2846);
            border-bottom: 1px solid rgba(255, 255, 255, .1);
            padding: 10px 16px;
            text-align: center;
            letter-spacing: .12em;
            font-weight: 800;
            color: #fff;
        }

        .scene {
            position: relative;
            min-height: 100dvh;
            padding: 14px 12px 80px;
            background:
                radial-gradient(1100px 700px at 10% 8%, rgba(83, 80, 170, .35), transparent 60%),
                radial-gradient(900px 600px at 90% 20%, rgba(70, 140, 255, .28), transparent 60%),
                radial-gradient(1200px 800px at 50% 85%, rgba(190, 80, 255, .22), transparent 60%);
        }

        .stars,
        .stars2,
        .stars3 {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            opacity: .85;
            background-repeat: repeat;
        }

        .stars {
            background-image: radial-gradient(1px 1px at 10px 20px, #fff, transparent 40%), radial-gradient(1px 1px at 130px 80px, #fff, transparent 40%), radial-gradient(1px 1px at 200px 150px, #fff, transparent 40%), radial-gradient(1px 1px at 300px 40px, #fff, transparent 40%);
            background-size: 320px 320px;
            animation: starDrift 160s linear infinite;
        }

        .stars2 {
            opacity: .6;
            background-image: radial-gradient(1.5px 1.5px at 40px 60px, rgba(255, 255, 255, .9), transparent 40%), radial-gradient(1.5px 1.5px at 160px 200px, rgba(255, 255, 255, .9), transparent 40%), radial-gradient(1.5px 1.5px at 260px 120px, rgba(255, 255, 255, .9), transparent 40%);
            background-size: 420px 420px;
            animation: starDrift 220s linear infinite reverse;
        }

        .stars3 {
            opacity: .45;
            background-image: radial-gradient(2px 2px at 80px 40px, rgba(255, 255, 255, .7), transparent 45%), radial-gradient(2px 2px at 300px 240px, rgba(255, 255, 255, .7), transparent 45%);
            background-size: 560px 560px;
            animation: starDrift 300s linear infinite;
        }

        @keyframes starDrift {
            from {
                transform: translate3d(0, 0, 0)
            }

            to {
                transform: translate3d(-200px, -200px, 0)
            }
        }

        #galaxy {
            position: relative;
            z-index: 1;
            max-width: 1100px;
            min-height: 56vh;
            border-radius: 20px;
            border: 1px solid var(--card-stroke);
            background: rgba(0, 0, 0, .22);
            backdrop-filter: blur(6px);
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0, 0, 0, .4);
        }

        .avatar {
            position: absolute;
            transform-origin: center;
            will-change: transform;
            transition: left 6s ease-in-out, top 6s ease-in-out;
        }

        .avatar .name {
            position: absolute;
            left: 50%;
            top: 100%;
            transform: translateX(-50%);
            margin-top: 4px;
            padding: 2px 8px;
            border-radius: 999px;
            background: rgba(0, 0, 0, .5);
            border: 1px solid rgba(255, 255, 255, .18);
            font-weight: 700;
            font-size: 12px;
            white-space: nowrap;
            color: #fff;
            text-shadow: 0 1px 2px rgba(0, 0, 0, .45);
        }

        .avatar.me .name {
            background: rgba(0, 60, 30, .6);
            border-color: rgba(126, 255, 161, .35);
            color: var(--me);
        }

        .av-wrap {
            width: 100%;
            height: 100%;
            animation: driftX var(--driftDur, 12s) ease-in-out infinite;
        }

        .av-core {
            width: 100%;
            height: 100%;
            animation: floatY var(--floatDur, 4s) ease-in-out infinite;
        }

        .av-visual {
            width: 100%;
            height: 100%;
            animation: wobble var(--wobbleDur, 6s) ease-in-out infinite;
        }

        @keyframes driftX {
            0% {
                transform: translateX(calc(-1 * var(--ampX, 24px)));
            }

            50% {
                transform: translateX(var(--ampX, 24px));
            }

            100% {
                transform: translateX(calc(-1 * var(--ampX, 24px)));
            }
        }

        @keyframes floatY {

            0%,
            100% {
                transform: translateY(calc(-1 * var(--ampY, 12px)));
            }

            50% {
                transform: translateY(var(--ampY, 12px));
            }
        }

        @keyframes wobble {

            0%,
            100% {
                transform: rotate(-7deg);
            }

            50% {
                transform: rotate(7deg);
            }
        }

        .card {
            position: relative;
            z-index: 2;
            max-width: 1100px;
            margin: 16px auto 0;
            padding: 18px 16px 20px;
            background: var(--card-bg);
            border: 1px solid var(--card-stroke);
            border-radius: 16px;
            backdrop-filter: blur(6px);
        }

        .muted {
            color: var(--space-dim)
        }

        .err {
            color: #ff8080;
            font-weight: 700
        }

        .space-input {
            width: min(420px, 92vw);
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, .18);
            background: rgba(255, 255, 255, .08);
            color: #fff;
            font-size: 16px;
            outline: none;
        }

        .space-input:focus {
            border-color: rgba(105, 240, 255, .6);
            box-shadow: 0 0 0 3px rgba(105, 240, 255, .15)
        }

        .space-button {
            margin-top: 6px;
            padding: 10px 16px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, .25);
            background: linear-gradient(180deg, rgba(105, 240, 255, .25), rgba(0, 0, 0, .2));
            color: #fff;
            font-weight: 800;
            cursor: pointer;
        }

        .space-button:hover {
            filter: brightness(1.06)
        }

        ul#participant-list {
            margin: .4rem 0 0 1rem
        }

        .join-inline {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: .4rem;
        }

        .join-label {
            font-weight: 700;
        }

        .join-inline .space-input {
            flex: 1 1 280px;
            min-width: 200px;
        }

        .join-inline .space-button {
            flex: 0 0 auto;
        }

        @media (max-width:480px) {
            .join-inline .space-button {
                width: 100%;
            }
        }

        .paused-banner {
            position: fixed;
            left: 50%;
            bottom: 14px;
            transform: translateX(-50%);
            z-index: 8;
            padding: 10px 14px;
            border-radius: 12px;
            background: rgba(0, 0, 0, .72);
            border: 1px solid var(--card-stroke);
            color: var(--space-ink);
            backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .paused-banner[hidden] {
            display: none;
        }

        .paused-banner .msg {
            opacity: .9;
        }

        /* 選択テーマのチップ表示 */
        .theme-wrap {
            margin-top: .6rem;
            display: flex;
            gap: .4rem;
            flex-wrap: wrap;
        }

        .theme-chip {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: 4px 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .10);
            border: 1px solid var(--card-stroke);
            font-size: 12px;
            color: var(--space-ink);
            white-space: nowrap;
        }

        .theme-chip small {
            opacity: .8;
        }
    </style>
    <!-- OGPタグ -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($ogTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($ogDesc) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($ogUrl) ?>">

    <!-- Twitterカード（任意だけどおすすめ）-->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($ogTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($ogDesc) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage) ?>">

</head>

<body class="space">
    <header class="space-header">推し問バトル – 待合室（宇宙ロビー）</header>

    <main class="scene">
        <div class="stars"></div>
        <div class="stars2"></div>
        <div class="stars3"></div>

        <div id="galaxy" aria-label="参加者の宇宙キャラクタ表示"></div>

        <section class="card">
            <h2 id="lobbyTitle">バトル開始待ち</h2>
            <div id="countdown" style="font-size:1.6rem;margin-top:.6rem;"></div>

            <?php if (!empty($error)): ?>
                <p class="err"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
            <?php endif; ?>

            <?php if ($alreadyJoined): ?>
                <p class="muted">あなたは「<strong style="color:var(--me)"><?= htmlspecialchars($currentName, ENT_QUOTES) ?></strong>」として参加中です。参加者が集まるまでお待ちください。「バトル開始」を押すとバトルが始まります。</p>
            <?php else: ?>
                <form method="post" autocomplete="off">
                    <div class="join-inline">
                        <label for="join-name" class="join-label">名前：</label>
                        <input id="join-name" class="space-input" type="text" name="name" maxlength="32" required placeholder="ニックネーム">
                        <button class="space-button" type="submit">参加する</button>
                    </div>
                </form>
                <p class="muted">※ 同じグループ内で同名は使用できません。</p>
            <?php endif; ?>

            <h3>出題テーマ</h3>
            <?php if (!empty($themes)): ?>
                <div class="theme-wrap">
                    <?php foreach ($themes as $t): ?>
                        <?php
                        $path = sprintf(
                            '%s / %s / %s',
                            (string)($t['c1_title'] ?? '—'),
                            (string)($t['c2_title'] ?? '—'),
                            (string)($t['theme_title'] ?? '—')
                        );
                        ?>
                        <span class="theme-chip">
                            <small><?= (int)$t['cate1'] ?>-<?= (int)$t['cate2'] ?>-<?= (int)$t['qid'] ?></small>
                            <?= htmlspecialchars($path, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="muted">出題テーマは未選択です。</p>
            <?php endif; ?>

            <h3>現在の参加者（一覧）</h3>
            <ul id="participant-list">
                <?php if ($participants): ?>
                    <?php foreach ($participants as $p): ?>
                        <li<?= ($alreadyJoined && $p === $currentName) ? ' style="color:var(--me);font-weight:700"' : '' ?>>
                            <?= htmlspecialchars($p, ENT_QUOTES) ?>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li>まだ参加者はいません。</li>
                    <?php endif; ?>
            </ul>

            <form method="get" action="ready.php" style="margin-top:12px;">
                <input type="hidden" name="gid" value="<?= (int)$gid ?>">
                <input type="hidden" name="bid" value="<?= (int)$bid ?>">
                <input type="hidden" name="n" value="3">
                <button class="space-button" type="submit" <?= $alreadyJoined ? '' : 'disabled' ?>>バトル開始</button>
            </form>
        </section>

        <div id="pausedBanner" class="paused-banner" hidden>
            <span class="msg" id="pausedMsg">省電力のため更新を一時停止しました。</span>
            <button id="resumeBtn" type="button" class="space-button">再開</button>
        </div>
    </main>

    <script>
        const titleEl = document.getElementById('lobbyTitle');
        const cdEl = document.getElementById('countdown');
        const rootBid = <?= (int)$rootBid ?>;
        const prevBid = <?= (int)$prevBid ?>;

        function setLobbyMode(mode) {
            if (!titleEl || !cdEl) return;
            if (mode === 'countdown') {
                titleEl.textContent = 'カウントダウン中';
            } else {
                titleEl.textContent = 'バトル開始待ち';
                cdEl.textContent = '';
            }
        }

        const bid = <?= (int)($_GET['bid'] ?? $bid ?? 0) ?>;
        const gid = <?= (int)($_GET['gid'] ?? $gid ?? 0) ?>;
        const myName = <?= json_encode($currentName ?? null, JSON_UNESCAPED_UNICODE) ?>;

        /* タイムアウト等（既存） */
        const POLL_INTERVAL_MS = 1000;
        const INACTIVE_TIMEOUT_MS = 10 * 60 * 1000;
        const HIDDEN_TIMEOUT_MS = 2 * 60 * 1000;
        const FETCH_TIMEOUT_MS = 7000;
        let lastActivity = Date.now(),
            hiddenSince = null,
            stopped = false,
            pollTimer = null,
            hbIv = null;

        function startHeartbeat() {
            if (hbIv) return;
            hbIv = setInterval(() => {
                fetch(`ping_battle.php?gid=${gid}&bid=${bid}`, {
                    cache: 'no-store',
                    credentials: 'same-origin'
                });
            }, 15000);
        }

        function stopHeartbeat() {
            if (hbIv) {
                clearInterval(hbIv);
                hbIv = null;
            }
        }

        const banner = document.getElementById('pausedBanner');
        const bannerMsg = document.getElementById('pausedMsg');
        document.getElementById('resumeBtn').addEventListener('click', resumePolling);

        function showPausedBanner(reason) {
            if (bannerMsg) bannerMsg.textContent = reason || '省電力のため更新を一時停止しました。';
            banner?.removeAttribute('hidden');
        }

        function hidePausedBanner() {
            banner?.setAttribute('hidden', '');
        }
        ['pointerdown', 'mousemove', 'keydown', 'touchstart', 'scroll', 'focus'].forEach(ev => {
            window.addEventListener(ev, () => {
                lastActivity = Date.now();
                if (stopped) resumePolling();
            }, {
                passive: true
            });
        });
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) hiddenSince = Date.now();
            else {
                hiddenSince = null;
                lastActivity = Date.now();
                if (stopped) resumePolling();
            }
        });

        function stopPolling(reason) {
            stopped = true;
            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }
            stopHeartbeat();
            SpriteEngine.pause();
            showPausedBanner(reason);
        }

        function resumePolling() {
            if (!stopped) return;
            stopped = false;
            hidePausedBanner();
            SpriteEngine.resume();
            poll();
            startHeartbeat();
        }
        setInterval(() => {
            const now = Date.now();
            if (!stopped && (now - lastActivity) > INACTIVE_TIMEOUT_MS) stopPolling('しばらく操作がなかったため自動停止しました。');
            if (!stopped && hiddenSince && (now - hiddenSince) > HIDDEN_TIMEOUT_MS) stopPolling('タブが非表示のため自動停止しました。');
        }, 30000);

        /* サーバ確定タイプを使って描画（クライアントでのランダム決定は廃止） */

        function urlFromType(type) {
            switch (type) {
                case 'satellite':
                    return 'images/satellite_sprite_320x320.png';
                case 'jupiter':
                    return 'images/jupiter_sprite_320x320.png';
                case 'martian':
                    return 'images/martian_sprite_320x320.png';
                case 'alien':
                    return 'images/alien_sprite_320x320.png';
                case 'rocket':
                    return 'images/rocket_sprite_320x320.png';
                case 'ufo':
                    return 'images/ufo_sprite_320x320.png';
                case 'robot':
                    return 'images/robot_sprite_320x320.png';
                case 'earth':
                    return 'images/earth_sprite_320x320.png';
                case 'sun':
                    return 'images/sun_sprite_320x320.png';
                case 'saturn':
                    return 'images/saturn_sprite_320x320.png';
                case 'human':
                    return 'images/human_sprite_320x320.png';
                default:
                    return null;
            }
        }

        function svgFor(type) {
            const base = 'images/svg';
            switch (type) {
                case 'saturn':
                    return `<img class="svg-ico" src="${base}/saturn.svg"   width="80" height="80" alt="土星">`;
                case 'sun':
                    return `<object class="svg-ico" type="image/svg+xml" data="${base}/sun.svg" width="78" height="78" aria-label="太陽"></object>`;
                case 'earth':
                    return `<img class="svg-ico" src="${base}/earth.svg"    width="74" height="74" alt="地球">`;
                case 'jupiter':
                    return `<img class="svg-ico" src="${base}/jupiter.svg"  width="84" height="84" alt="木星">`;
                case 'alien':
                    return `<img class="svg-ico" src="${base}/alien.svg"    width="64" height="64" alt="宇宙人">`;
                case 'martian':
                    return `<img class="svg-ico" src="${base}/martian.svg"  width="62" height="62" alt="火星人">`;
                case 'human':
                    return `<img class="svg-ico" src="${base}/human.svg"    width="64" height="64" alt="宇宙服の人">`;
                case 'satellite':
                    return `<img class="svg-ico" src="${base}/satellite.svg" width="66" height="66" alt="人工衛星">`;
                case 'rocket':
                    return `<img class="svg-ico" src="${base}/rocket.svg"   width="64" height="64" alt="ロケット">`;
                case 'ufo':
                    return `<img class="svg-ico" src="${base}/ufo.svg"      width="72" height="72" alt="UFO">`;
                case 'robot':
                    return `<img class="svg-ico" src="${base}/robot.svg"    width="64" height="64" alt="ロボット">`;
                default:
                    return `<img class="svg-ico" src="${base}/dot.svg"      width="10" height="10" alt="">`;
            }
        }

        const avatars = new Map(); // name -> element

        // --- FIX START ---
        function ensureAvatar(name, isMe = false, forcedType = null) {
            // サーバ未確定なら出さない（仮絵なし）
            if (!forcedType) return null;
            if (avatars.has(name)) return avatars.get(name);

            const type = forcedType;

            // 名前から安定乱数
            const seed = (name || '').split('')
                .reduce((h, c) => Math.imul(h ^ c.charCodeAt(0), 16777619) >>> 0, 2166136261 >>> 0);
            let x = seed || 1;
            const rand = () => {
                x ^= x << 13;
                x ^= x >>> 17;
                x ^= x << 5;
                return ((x >>> 0) / 4294967296);
            };

            const el = document.createElement('div');
            el.className = 'avatar' + (isMe ? ' me' : '');
            el.dataset.name = name;
            el.dataset.type = type;

            const base = 56 + Math.floor(rand() * 20);
            el.style.width = base + 'px';
            el.style.height = base + 'px';
            const galaxy = document.getElementById('galaxy');
            const gw = galaxy.clientWidth,
                gh = galaxy.clientHeight;
            el.style.left = (Math.floor(rand() * (gw - base - 20)) + 10) + 'px';
            el.style.top = (Math.floor(rand() * (gh - base - 20)) + 10) + 'px';
            el.style.setProperty('--ampX', (22 + Math.floor(rand() * 26)) + 'px');
            el.style.setProperty('--ampY', (10 + Math.floor(rand() * 14)) + 'px');
            el.style.setProperty('--driftDur', (8 + rand() * 8).toFixed(2) + 's');
            el.style.setProperty('--floatDur', (2.8 + rand() * 2).toFixed(2) + 's');
            el.style.setProperty('--wobbleDur', (3.2 + rand() * 3).toFixed(2) + 's');

            const wrap = document.createElement('div');
            wrap.className = 'av-wrap';
            const core = document.createElement('div');
            core.className = 'av-core';
            const visual = document.createElement('div');
            visual.className = 'av-visual';
            wrap.appendChild(core);
            core.appendChild(visual);
            el.appendChild(wrap);

            const url = urlFromType(type);
            if (url) {
                const sprite = document.createElement('div');
                sprite.className = 'sprite';
                visual.appendChild(sprite);
                SpriteEngine.attach(sprite, url);
                el._cleanup = () => SpriteEngine.detach(sprite);
            } else {
                const holder = document.createElement('div');
                holder.innerHTML = svgFor(type);
                visual.appendChild(holder.firstElementChild || holder);
                el._cleanup = null;
            }

            const tag = document.createElement('div');
            tag.className = 'name';
            tag.textContent = name;
            el.appendChild(tag);

            const moveIv = setInterval(() => {
                const nx = Math.floor(rand() * (gw - base - 20)) + 10;
                const ny = Math.floor(rand() * (gh - base - 20)) + 10;
                el.style.left = nx + 'px';
                el.style.top = ny + 'px';
            }, 12000 + Math.floor(rand() * 8000));
            el.dataset.moveIv = String(moveIv);

            galaxy.appendChild(el);
            avatars.set(name, el);
            return el;
        }

        function removeMissing(currentNames) {
            const set = new Set(currentNames);
            for (const [name, el] of avatars.entries()) {
                if (!set.has(name)) {
                    if (el.dataset.moveIv) clearInterval(Number(el.dataset.moveIv));
                    if (typeof el._cleanup === 'function') el._cleanup();
                    el.remove();
                    avatars.delete(name);
                }
            }
        }

        function updateGalaxy(list, myName) {
            const galaxy = document.getElementById('galaxy');
            if (!galaxy) return;
            if (!Array.isArray(list)) list = [];

            list.forEach(item => {
                const name = (typeof item === 'string') ? item : item.name;
                const type = (typeof item === 'string') ? null : item.type;
                ensureAvatar(name, myName && name === myName, type); // サーバ確定タイプのみ描画
            });
            removeMissing(list.map(it => (typeof it === 'string') ? it : it.name));
        }

        // 以降はそのまま（IIFE内で poll / renderParticipantsList など）
        (() => {
            let navigated = false,
                countdownIv = null,
                offset = 0,
                offsetFixed = false;
            const now = () => Date.now() + offset;

            function goStart() {
                if (navigated) return;
                navigated = true;
                location.href = `start.php?bid=${bid}&gid=${gid}`;
            }

            function renderParticipantsList(list) {
                const ul = document.getElementById('participant-list');
                if (!ul) return;
                ul.innerHTML = '';
                if (!Array.isArray(list) || list.length === 0) {
                    ul.innerHTML = '<li>まだ参加者はいません。</li>';
                    return;
                }
                for (const name of list) {
                    const li = document.createElement('li');
                    li.textContent = name;
                    if (myName && name === myName) {
                        li.style.color = 'var(--me)';
                        li.style.fontWeight = '700';
                    }
                    ul.appendChild(li);
                }
            }

            function startCountdown(startAtMs) {
                const el = document.getElementById('countdown');
                if (!el || countdownIv) return;
                setLobbyMode('countdown');
                countdownIv = setInterval(() => {
                    const remainSec = Math.ceil((startAtMs - now()) / 1000);
                    if (remainSec > 0) {
                        el.textContent = `開始まで ${remainSec} 秒`;
                    } else {
                        el.textContent = '開始します…';
                        clearInterval(countdownIv);
                        countdownIv = null;
                        goStart();
                    }
                }, 200);
            }

            async function poll() {
                if (stopped) return;
                try {
                    const ctrl = new AbortController();
                    const t = setTimeout(() => ctrl.abort(), FETCH_TIMEOUT_MS);
                    const res = await fetch(
                        `get_lobby_status.php?bid=${bid}&gid=${gid}&root=${rootBid}&prev=${prevBid}`, {
                            cache: 'no-store',
                            credentials: 'same-origin',
                            signal: ctrl.signal
                        }
                    ).then(r => r.json()).finally(() => clearTimeout(t));

                    if (typeof res.now === 'number' && !offsetFixed) {
                        offset = res.now - Date.now();
                        offsetFixed = true;
                    }

                    renderParticipantsList(res.participants || []);
                    updateGalaxy(res.avatars || [], myName); // 銀河は avatars のみ

                    if (res.started && typeof res.start_at === 'number') {
                        const remain = res.start_at - now();
                        if (res.phase === 0 && remain > 0) {
                            startCountdown(res.start_at);
                        } else {
                            goStart();
                            return;
                        }
                    } else {
                        setLobbyMode('wait');
                    }
                } catch (_) {
                    /* retry next tick */
                }

                if (!navigated && !stopped) {
                    pollTimer = setTimeout(poll, POLL_INTERVAL_MS);
                }
            }

            // 初期描画：サーバ決定済みのみ表示
            updateGalaxy(<?= json_encode($initialAvatars, JSON_UNESCAPED_UNICODE) ?>, myName);
            startHeartbeat();
            poll();
        })();
        // --- FIX END ---
    </script>
</body>

</html>
