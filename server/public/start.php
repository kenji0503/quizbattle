<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/logs/logger.php';
require_once __DIR__ . '/common/define.php';
require_once __DIR__ . '/common/battle_common.php'; // dbConnectPDO() は上のconfigで繋がる前提

$log = Logger::getInstance();
$log->debug('** start.php (battle) start');

// セッション必須（join_battle.php で uid/gid/bid が入る想定）
if (empty($_SESSION['uid']) || empty($_SESSION['gid'])) {
    $log->debug('start.php session missing: uid=' . ($_SESSION['uid'] ?? 'null') . ', gid=' . ($_SESSION['gid'] ?? 'null'));
    header("Location: user/login.php"); // もしくは join_battle.php へ誘導
    exit;
}

// 基本情報
$uid = (int)($_SESSION['uid']);
$gid = (int)($_SESSION['gid']);

// bid は GET優先→セッション
if (isset($_GET['bid'])) {
    $bid = (int)$_GET['bid'];
    $_SESSION['bid'] = $bid;
} else {
    $bid = (int)($_SESSION['bid'] ?? 0);
}
if ($bid <= 0) {
    die('バトルIDが未指定です。join_battle.php から入り直してください。');
}

// 表示用ユーザ名（環境に合わせて実装:getUserNameがqb_user対応ならそのまま）
$userName = '';
try {
    $userName = getUserName($gid, $uid);
} catch (\Throwable $e) {
    $userName = htmlspecialchars($_SESSION['name'] ?? 'ゲスト', ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta http-equiv="Content-Language" content="ja" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>クイズバトル</title>
    <link rel="stylesheet" href="css/battle.css">
    <link rel="stylesheet" href="css/ui-common.css">
    <style>
        #ranking th {
            background-color: #FFD700;
            color: #000;
            padding: 8px;
        }

        #ranking td,
        #ranking th {
            border: 1px solid #ccc;
            padding: 6px 8px;
        }

        #ranking td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: center;
            color: #fff;
        }

        #ranking {
            width: auto;
            margin: 20px auto;
        }

        #question-stage {
            display: grid;
            grid-template-columns: minmax(96px, 160px) minmax(0, 1fr);
            gap: 14px;
            width: 90%;
            margin: 18px auto 0;
            align-items: stretch;
        }

        #question-wrap {
            min-width: 0;
        }

        #question-stage.is-reveal {
            grid-template-columns: minmax(0, 1fr);
        }

        #question-stage.is-ready {
            grid-template-columns: minmax(0, 1fr);
        }

        #question-stage.is-reveal #question-wrap {
            grid-column: 1 / -1;
        }

        #question-stage.is-ready #question-wrap {
            grid-column: 1 / -1;
        }

        #question {
            background-color: #2c2c4a;
            border: 3px solid #FFD700;
            border-radius: 10px;
            padding: 20px;
            width: 100%;
            margin: 0;
            font-size: 1.5em;
            text-align: left;
            white-space: pre-line;
            box-sizing: border-box;
            min-height: 100%;
        }

        #countdown-panel {
            display: none;
            margin: 0;
            padding: 14px 12px;
            border: 2px solid rgba(255, 215, 0, 0.65);
            border-radius: 14px;
            background: rgba(255, 215, 0, 0.08);
            text-align: center;
            color: #fff7c2;
            box-sizing: border-box;
            align-self: stretch;
        }

        #countdown-label {
            font-size: 0.9rem;
            font-weight: 700;
            letter-spacing: 0.04em;
        }

        #countdown-value {
            margin-top: 8px;
            font-size: clamp(2.8rem, 8vw, 4.8rem);
            font-weight: 800;
            line-height: 1;
            color: #FFD700;
        }

        #choices {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            width: 90%;
            margin: 18px auto 30px;
        }

        #info-area {
            width: 90%;
            margin-left: 30px;
            /* text-align: center; */
            font-size: 2.1em;
        }

        #battleControls {
            margin-top: 18px;
        }

        /* 問題見出し（控えめ・自然） */
        #q-headline {
            width: 100%;
            margin: 0 0 10px;
            /* 質問の直前に軽く余白 */
            padding: 0;
            /* ベタ塗りはしない */
            color: #E8EAF6;
            /* 淡い文字色（背景#1b1b2fに対して十分なコントラスト） */
            font-size: 1.0em;
            /* 本文より少し小さめ */
            font-weight: 600;
            /* 強すぎない太さ */
            line-height: 1.5;
            letter-spacing: .02em;
            box-sizing: border-box;
        }

        #q-headline:empty {
            display: none;
        }

        #q-headline::before {
            content: "";
            display: inline-block;
            width: 8px;
            height: 8px;
            margin-right: 8px;
            border-radius: 50%;
            background: #FFD700;
            /* 小さなアクセント */
            box-shadow: 0 0 0 2px rgba(255, 215, 0, .25);
            /* ほんのり縁取り */
            transform: translateY(-1px);
        }

        .choice-button {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            text-align: left;
            background-color: #fff;
            color: #000;
            border: 2px solid #ccc;
            border-radius: 8px;
            padding: 15px 20px;
            font-size: 1.2em;
            cursor: pointer;
            transition: transform .1s, box-shadow .2s;
            width: 100%;
            box-sizing: border-box;
        }

        .choice-label {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            text-align: center;
            color: #fff;
            font-weight: bold;
            margin-right: 15px;
            flex-shrink: 0;
        }

        .label-A {
            background-color: #d32f2f;
        }

        .label-B {
            background-color: #1976d2;
        }

        .label-C {
            background-color: #388e3c;
        }

        .label-D {
            background-color: #fbc02d;
            color: #000;
        }

        .choice-button:active {
            transform: scale(0.97);
            box-shadow: inset 0 0 10px rgba(0, 0, 0, 0.3);
        }

        .choice-button.selected {
            border-color: #FF9800 !important;
            border-width: 5px;
            box-shadow: 0 0 0 3px rgba(255, 152, 0, .25);
        }

        .choice-button[disabled].selected {
            opacity: 1;
        }

        @media all and (max-width: 600px) {
            #question-stage {
                grid-template-columns: minmax(72px, 92px) minmax(0, 1fr);
                gap: 10px;
                width: 94%;
            }

            #countdown-panel {
                padding: 10px 6px;
            }

            #countdown-value {
                font-size: clamp(2rem, 8vw, 2.8rem);
            }

            #countdown-label {
                display: none;
            }

            #question {
                padding: 14px;
                font-size: 1.2em;
            }

            #choices {
                grid-template-columns: 1fr;
                width: 94%;
            }
        }

        #topBar {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            gap: 12px;
            align-items: center;
            overflow: hidden;
        }

        #topBar .title-wrapper {
            min-width: 0;
        }

        .player-name {
            color: #000;
            font-weight: bold;
            min-width: 0;
            max-width: 22vw;
            overflow: hidden;
        }

        #sound-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border: 1px solid rgba(0, 0, 0, 0.16);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.9);
            color: #111;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        #sound-toggle .icon-muted {
            display: none;
        }

        #sound-toggle[data-muted="1"] .icon-on {
            display: none;
        }

        #sound-toggle[data-muted="1"] .icon-muted {
            display: inline;
        }

        @media all and (max-width: 600px) {
            #topBar {
                grid-template-columns: 1fr auto;
            }

            .player-name {
                display: none;
            }

            #sound-toggle {
                justify-self: end;
                min-width: 44px;
                padding: 8px 10px;
                gap: 0;
            }

            #sound-toggle-label {
                display: none;
            }
        }
    </style>

    <script>
        // JS側へ埋め込み（battle-runtime.js が参照）
        window.__BATTLE_ID__ = <?= json_encode($bid) ?>; // bid
        window.__GROUP_ID__ = <?= json_encode($gid) ?>; // gid
        window.__USER_ID__ = <?= json_encode($uid) ?>; // uid
        window.__QB_WS_URL__ = <?= json_encode(battle_ws_public_url(), JSON_UNESCAPED_SLASHES) ?>;
    </script>
</head>

<body id="blackBody">
    <div id="topBar">
        <div class="title-wrapper">
            <h1 id="battle-title" class="ellipsis no-space"></h1>
        </div>

        <button id="sound-toggle" type="button" aria-pressed="false" aria-label="音声をオフにする">
            <span class="icon-on" aria-hidden="true">🔊</span>
            <span class="icon-muted" aria-hidden="true">🔇</span>
            <span id="sound-toggle-label">音あり</span>
        </button>

        <div class="player-name">
            <div class="ellipsis"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?> さん</div>
        </div>

    </div>

    <div id="maincontents">
        <div id="polling-message" style="display:none; text-align:center; padding:1em; margin-top:2em; background-color:#ffeb3b; color:#000; font-weight:bold;">
            ポーリングは1時間で自動的に停止しました。
        </div>

        <!-- 問題 -->
        <div id="question-stage">
            <div id="countdown-panel">
                <div id="countdown-label">問題を出題します</div>
                <div id="countdown-value"></div>
            </div>
            <div id="question-wrap">
                <div id="q-headline"></div>
                <div id="question"></div>
            </div>
        </div>

        <!-- メッセージ -->
        <div id="message-area" style="color:#ff8a80; font-weight:bold; margin-top:10px;"></div>

        <!-- 選択肢 -->
        <div id="choices">
            <div id="sentakuA"></div>
            <div id="sentakuB"></div>
            <div id="sentakuC"></div>
            <div id="sentakuD"></div>
        </div>

        <!-- 情報 -->
        <div id="info-area">
            <div id="answer-count">現在の解答者数: 0人</div>
            <div id="battleControls"></div>
        </div>

        <!-- デバッグログ -->
        <!-- 
        <div id="debug-log" style="position:fixed;left:0;right:0;bottom:0;max-height:30vh;overflow:auto;background:#101010;color:#8f8;padding:4px 8px;font:12px/1.4 monospace;z-index:99999;opacity:.95"></div>
         -->

    </div>

    <div hidden aria-hidden="true">
        <audio id="sound-answerBtn" preload="auto" playsinline>
            <source src="sound/answerBtn.mp3" type="audio/mpeg">
        </audio>
        <audio id="sound-beep" preload="auto" playsinline>
            <source src="sound/beep.mp3" type="audio/mpeg">
        </audio>
        <audio id="sound-kotae" preload="auto" playsinline>
            <source src="sound/kotae.mp3" type="audio/mpeg">
        </audio>
        <audio id="sound-countdown" preload="auto" playsinline>
            <source src="sound/countdown.mp3" type="audio/mpeg">
        </audio>
        <audio id="sound-mondai" preload="auto" playsinline>
            <source src="sound/mondai.mp3" type="audio/mpeg">
        </audio>
    </div>

    <?php $v = urlencode((string)@filemtime(__DIR__ . '/js/battle-runtime.js')); ?>
    <script src="js/battle-runtime.js?v=<?= $v ?>"></script>

    <!-- 自動遷移は battle-runtime.js に一本化 -->

</body>

</html>
