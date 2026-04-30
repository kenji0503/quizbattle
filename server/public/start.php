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

        #question {
            background-color: #2c2c4a;
            border: 3px solid #FFD700;
            border-radius: 10px;
            padding: 20px;
            width: 90%;
            margin: 40px auto;
            font-size: 1.5em;
            text-align: left;
            white-space: pre-line;
        }

        #choices {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            width: 90%;
            margin: 30px auto;
        }

        #info-area {
            width: 90%;
            margin-left: 30px;
            /* text-align: center; */
            font-size: 2.1em;
        }

        /* 問題見出し（控えめ・自然） */
        #q-headline {
            width: 90%;
            margin: 18px auto 6px;
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

        /* 見出しの直後に来る問題ボックスの間隔を少し詰める（任意） */
        #q-headline+#question {
            margin-top: 16px;
            /* 既存 40px → 16px に */
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
            #choices {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <script>
        // JS側へ埋め込み（polling.js が参照）
        window.__BATTLE_ID__ = <?= json_encode($bid) ?>; // bid
        window.__GROUP_ID__ = <?= json_encode($gid) ?>; // gid
        window.__USER_ID__ = <?= json_encode($uid) ?>; // uid
    </script>
</head>

<body id="blackBody">
    <div id="topBar">
        <div class="title-wrapper">
            <h1 class="ellipsis no-space">推し問バトル</h1>
        </div>

        <div style=" color:#000; font-weight:bold;">
            <div class="ellipsis"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?> さん</div>
        </div>

    </div>

    <div id="maincontents">
        <div id="polling-message" style="display:none; text-align:center; padding:1em; margin-top:2em; background-color:#ffeb3b; color:#000; font-weight:bold;">
            ポーリングは1時間で自動的に停止しました。
        </div>

        <!-- 問題 -->
        <div id="q-headline"></div>
        <div id="question"></div>

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

    <?php $v = urlencode((string)@filemtime(__DIR__ . '/polling.js')); ?>
    <script src="js/polling.js?v=<?= time() ?>"></script>

    <!-- 自動遷移は polling.js に一本化 -->

</body>

</html>
