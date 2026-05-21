<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/logs/logger.php';
require_once __DIR__ . '/common/define.php';
require_once __DIR__ . '/common/battle_common.php';
require_once __DIR__ . '/common/question_repository.php';

$log = Logger::getInstance();
$log->debug('** battle.php start');

// DB接続
$pdo = dbConnectPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// GETパラメータ（推し問から単一指定される場合用）
$param_c1  = filter_input(INPUT_GET, 'cate1', FILTER_VALIDATE_INT);
$param_c2  = filter_input(INPUT_GET, 'cate2', FILTER_VALIDATE_INT);
$param_qid = filter_input(INPUT_GET, 'id',    FILTER_VALIDATE_INT);
$hasDirectScopeParams = ($param_c1 !== null && $param_c1 !== false && $param_c1 > 0)
    && ($param_c2 !== null && $param_c2 !== false && $param_c2 > 0)
    && ($param_qid !== null && $param_qid !== false && $param_qid > 0);

$base = '';

// タイトルの自動補完
$prefillTitle = isset($_POST['title']) ? trim($_POST['title']) : '';
if ($prefillTitle === '' && $hasDirectScopeParams) {
    $prefetchError = null;
    question_ensure_category_path($pdo, $param_c1, $param_c2, $param_qid, $prefetchError);
    $info = getTitle($param_c1, $param_c2, $param_qid); // ['genre'=>..., 'title'=>...]
    if (is_array($info)) {
        $base = trim((string)($info['title'] ?? ''));
        if ($base === '') $base = trim((string)($info['genre'] ?? ''));
    } else {
        $base = trim((string)$info);
    }
    if ($base !== '') {
        if (!ini_get('date.timezone')) date_default_timezone_set('Asia/Tokyo');
        $prefillTitle = $base . 'クイズバトル' . date('Y');
    }
}

// 上記でも補完されてなければ「クイズバトル」に設定
if ($prefillTitle === '') {
    $prefillTitle = $base . 'クイズバトル' . date('Y');
}

function loadSelectedThemes(PDO $pdo, int $bid): array
{
    $sql = '
        SELECT s.cate1, s.cate2, s.qid AS id, COALESCE(h.title, "") AS title
        FROM qb_battle_scope s
        LEFT JOIN qb_question_category h
          ON h.cate1 = s.cate1 AND h.cate2 = s.cate2 AND h.qid = s.qid
        WHERE s.bid = :bid
        ORDER BY s.cate1, s.cate2, s.qid
    ';
    $st = $pdo->prepare($sql);
    $st->execute([':bid' => $bid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function createBattleRoom(PDO $pdo, array $selectedScopes, string $title, int $makeUid): array
{
    $selectedScopes = array_values(array_map('unserialize', array_unique(array_map('serialize', $selectedScopes))));
    if (empty($selectedScopes)) {
        throw new RuntimeException('問題を1つ以上選択してください。');
    }

    $availableQuestions = 0;
    foreach ($selectedScopes as [$scopeC1, $scopeC2, $scopeQid]) {
        $syncError = null;
        if (!question_ensure_category_path($pdo, $scopeC1, $scopeC2, $scopeQid, $syncError)) {
            throw new RuntimeException($syncError ?: 'カテゴリ情報の取得に失敗しました。');
        }
        $availableQuestions += question_sync_set($pdo, $scopeC1, $scopeC2, $scopeQid, $syncError);
        if ($syncError !== null && $syncError !== '') {
            throw new RuntimeException($syncError);
        }
    }

    if ($availableQuestions < 3) {
        throw new RuntimeException('選択したテーマ内の問題数が不足しています。3問以上必要です。');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO qb_group (name, status, playcount, date) VALUES (:name, 0, 0, CURDATE())");
        $stmt->execute([':name' => $title . 'グループ']);
        $gid = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO qb_battle (gid, title, description, make_uid, status, date)
                   VALUES (:gid, :title, 'クイズバトル', :uid, 0, CURDATE())");
        $stmt->execute([
            ':gid' => $gid,
            ':title' => $title,
            ':uid' => $makeUid,
        ]);
        $bid = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare('INSERT IGNORE INTO qb_battle_scope (bid, cate1, cate2, qid, weight)
                              VALUES (:bid,:c1,:c2,:qid,1)');
        foreach ($selectedScopes as [$scopeC1, $scopeC2, $scopeQid]) {
            $ins->execute([':bid' => $bid, ':c1' => $scopeC1, ':c2' => $scopeC2, ':qid' => $scopeQid]);
        }

        $pdo->commit();
        return ['gid' => $gid, 'bid' => $bid];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

$created_urls = null;
$error = '';
$mode = $_GET['mode'] ?? 'create';
$title = '';

// 既存バトルURL表示
if ($mode === 'show') {
    $bid = (int)($_GET['bid'] ?? 0);
    if ($bid) {
        $stmt = $pdo->prepare("SELECT b.bid, b.title, b.gid 
                         FROM qb_battle b
                        WHERE b.bid = :bid");

        $stmt->execute([':bid' => $bid]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $gid   = (int)$row['gid'];
            $title = $row['title'] ?? '';
            $created_urls = [
                'join'  => abs_url("join_battle.php?gid={$gid}&bid={$bid}"),
                'start' => abs_url("start_battle.php?bid={$bid}"),
                'bid'   => $bid,
            ];
            $selected_themes = loadSelectedThemes($pdo, $bid);
        } else {
            $error = "指定されたバトルが存在しません。";
        }
    } else {
        $error = "bid が指定されていません。";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $mode === 'create' && $hasDirectScopeParams) {
    try {
        $created = createBattleRoom(
            $pdo,
            [[(int)$param_c1, (int)$param_c2, (int)$param_qid]],
            $prefillTitle,
            (int)($_SESSION['uid'] ?? 0)
        );
        $_SESSION['bid'] = $created['bid'];
        header('Location: battle.php?mode=show&bid=' . (int)$created['bid']);
        exit;
    } catch (Throwable $e) {
        $error = "作成中にエラーが発生しました: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $log->error($error);
    }
}

// 新規作成
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'create') {
    $title = trim($_POST['title'] ?? '');
    // 複数選択チェックボックス（"c1-c2-id" 形式）
    $scopes = isset($_POST['scopes']) && is_array($_POST['scopes']) ? $_POST['scopes'] : [];

    if ($title === '') {
        $error = "バトル名を入力してください。";
    } else {
        try {
            $selectedScopes = [];

            $post_c1  = filter_input(INPUT_POST, 'cate1', FILTER_VALIDATE_INT);
            $post_c2  = filter_input(INPUT_POST, 'cate2', FILTER_VALIDATE_INT);
            $post_qid = filter_input(INPUT_POST, 'id',    FILTER_VALIDATE_INT);
            if ($post_c1 && $post_c2 && $post_qid) {
                $selectedScopes[] = [$post_c1, $post_c2, $post_qid];
            }

            foreach ($scopes as $s) {
                if (preg_match('/^(\d+)-(\d+)-(\d+)$/', $s, $m)) {
                    $selectedScopes[] = [(int)$m[1], (int)$m[2], (int)$m[3]];
                }
            }

            $created = createBattleRoom($pdo, $selectedScopes, $title, (int)($_SESSION['uid'] ?? 0));
            $gid = (int)$created['gid'];
            $bid = (int)$created['bid'];
            $_SESSION['bid'] = $bid;
            $created_urls = [
                'join'  => abs_url("join_battle.php?gid={$gid}&bid={$bid}"),
                'start' => abs_url("start_battle.php?bid={$bid}"),
                'bid'   => $bid,
            ];
            $selected_themes = loadSelectedThemes($pdo, $bid);
        } catch (Throwable $e) {
            $error = "作成中にエラーが発生しました: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            $log->error($error);
        }
    }
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Language" content="ja" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="apple-touch-icon" href="images/icon/icon.png">
    <link rel="icon" href="images/icon/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="css/ui-common.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <title>クイズバトル作成</title>
    <style>
        :root {
            --space-bg: #060814;
            --space-ink: #e6eaf2;
            --space-dim: #a9b1c4;
            --card-bg: rgba(255, 255, 255, .06);
            --card-stroke: rgba(255, 255, 255, .12);
            --accent: #69f0ff;
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
            z-index: 5;
            background: linear-gradient(90deg, #181a3a, #2a1a47 40%, #0b2846);
            border-bottom: 1px solid rgba(255, 255, 255, .1);
            padding: 10px 16px;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 12px;
            color: #fff;
        }

        .header-title {
            min-width: 0;
            text-align: center;
            letter-spacing: .12em;
            font-weight: 800;
            font-size: clamp(18px, 2.1vw, 24px);
            text-shadow: 0 1px 6px rgba(0, 0, 0, .45);
        }

        .header-back {
            justify-self: end;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid rgba(255, 209, 102, .7);
            background: linear-gradient(180deg, #ffe08a, #ffbf1f);
            color: #231900;
            font-weight: 900;
            text-decoration: none;
            white-space: nowrap;
            box-shadow: 0 8px 18px rgba(255, 191, 31, .22);
        }

        .header-back:hover {
            filter: brightness(1.03);
        }

        .space-scene {
            position: relative;
            min-height: 100dvh;
            padding: 10px 16px 64px;
            background:
                radial-gradient(1200px 700px at 15% 10%, rgba(83, 80, 170, .35), transparent 60%),
                radial-gradient(900px 600px at 85% 25%, rgba(70, 140, 255, .28), transparent 60%),
                radial-gradient(1000px 800px at 50% 80%, rgba(190, 80, 255, .22), transparent 60%);
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
            background-image:
                radial-gradient(1px 1px at 10px 20px, #fff, transparent 40%),
                radial-gradient(1px 1px at 130px 80px, #fff, transparent 40%),
                radial-gradient(1px 1px at 200px 150px, #fff, transparent 40%),
                radial-gradient(1px 1px at 300px 40px, #fff, transparent 40%);
            background-size: 320px 320px;
            animation: starDrift 160s linear infinite;
        }

        .stars2 {
            opacity: .6;
            background-image:
                radial-gradient(1.5px 1.5px at 40px 60px, rgba(255, 255, 255, .9), transparent 40%),
                radial-gradient(1.5px 1.5px at 160px 200px, rgba(255, 255, 255, .9), transparent 40%),
                radial-gradient(1.5px 1.5px at 260px 120px, rgba(255, 255, 255, .9), transparent 40%);
            background-size: 420px 420px;
            animation: starDrift 220s linear infinite reverse;
        }

        .stars3 {
            opacity: .45;
            background-image:
                radial-gradient(2px 2px at 80px 40px, rgba(255, 255, 255, .7), transparent 45%),
                radial-gradient(2px 2px at 300px 240px, rgba(255, 255, 255, .7), transparent 45%);
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

        .card {
            position: relative;
            z-index: 1;
            max-width: 960px;
            margin: 10px auto 28px;
            padding: 24px;
            background: var(--card-bg);
            backdrop-filter: blur(6px);
            border: 1px solid var(--card-stroke);
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, .35);
        }

        .card h1,
        .card h2 {
            margin: 0 0 12px;
            font-size: clamp(18px, 2.6vw, 28px);
        }

        .hint {
            color: var(--space-dim);
            margin: .6rem 0 .2rem;
            font-weight: 600;
        }

        .join-url {
            display: inline-block;
            word-break: break-all;
            line-height: 1.5;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Courier New", monospace;
            text-decoration: none;
            border-bottom: 1px dotted rgba(255, 255, 255, .35);
            color: #fff;
            transition: opacity .2s;
        }

        .join-url:hover {
            opacity: .85
        }

        .copy-link {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            margin-top: .4rem;
            font-weight: 700;
            text-decoration: underline;
            text-underline-offset: 2px;
            color: var(--accent);
            cursor: pointer;
        }

        .copy-link .icon {
            filter: drop-shadow(0 0 6px rgba(105, 240, 255, .4));
        }

        .quiz-main {
            margin: 0 0 12px;
        }

        .quiz-main-label {
            display: block;
            color: #ffd76e;
            font-size: 13px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: none;
        }

        .quiz-main-titles {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 8px;
        }

        .quiz-main-title {
            display: inline-block;
            color: #ffb24d;
            font-size: clamp(22px, 4vw, 34px);
            font-weight: 900;
            line-height: 1.08;
            letter-spacing: .02em;
        }

        .join-area {
            margin-top: 0;
        }

        .join-area .hint {
            margin: .15rem 0 0;
            line-height: 1.15;
        }

        .join-url-wrap {
            margin: 4px 0 0;
            line-height: 1.25;
        }

        .copy-link-wrap {
            margin: 4px 0 0;
            line-height: 1.1;
        }

        .qr {
            margin-top: 16px;
            padding: 12px;
            display: inline-block;
            background: rgba(0, 0, 0, .35);
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 12px;
        }

        .qr img {
            display: block;
            width: 110px;
            /* 見た目は半分 */
            height: auto;
        }

        .qr-share-row {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 14px;
            margin-top: 16px;
            width: 100%;
        }

        .qr-share-row .qr {
            margin-top: 0;
            flex: 0 0 auto;
        }

        .share-actions {
            display: flex;
            align-items: flex-end;
            margin: 0;
            margin-left: auto;
        }

        .share-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .4rem;
            min-height: 24px;
            padding: 4px 16px;
            border: 1px solid rgba(255, 255, 255, .18);
            border-radius: 999px;
            background: rgba(255, 255, 255, .08);
            color: #fff;
            font-size: 13px;
            font-weight: 800;
            white-space: nowrap;
            text-decoration: none;
            transition: transform .18s ease, filter .18s ease;
        }

        .share-button:hover {
            filter: brightness(1.08);
            transform: translateY(-1px);
        }

        /* 置き換え */
        .space-input {
            /* width: min(520px, 92vw);  ← これがはみ出しの原因 */
            width: 100%;
            /* 親の内容幅に合わせる */
            max-width: 520px;
            /* PCでは広がりすぎないよう上限 */
            box-sizing: border-box;
            /* パディング込みで100%に収める */
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, .18);
            background: rgba(255, 255, 255, .08);
            color: #fff;
            font-size: 16px;
            outline: none;
        }

        /* 追加：フォーム行の横幅をカードの内側に合わせやすくする */
        .card form {
            max-width: 100%;
        }

        .card form .form-row {
            display: grid;
            grid-template-columns: 96px minmax(0, 1fr);
            gap: 12px;
            align-items: center;
            margin: 0 0 12px;
        }

        .card form label {
            font-weight: 700;
        }

        /* 追加：モバイル時はカードの内側余白を少し減らして余裕を作る（任意） */
        @media (max-width: 480px) {
            .card {
                padding: 18px 16px;
            }

            .card form .form-row {
                grid-template-columns: 1fr;
                gap: 8px;
            }
        }


        .space-input:focus {
            border-color: rgba(105, 240, 255, .6);
            box-shadow: 0 0 0 3px rgba(105, 240, 255, .15);
        }

        .space-button {
            margin-top: 10px;
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

        /* 宇宙のデコ */
        .deco {
            position: fixed;
            z-index: 0;
            pointer-events: none;
            opacity: .9;
        }

        .float-slow {
            animation: floatY 8s ease-in-out infinite;
        }

        .float-slower {
            animation: floatY 11s ease-in-out infinite;
        }

        @keyframes floatY {

            0%,
            100% {
                transform: translate(-20px, -8px)
            }

            50% {
                transform: translate(20px, 8px)
            }
        }

        .spin-slow {
            animation: spin 36s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg)
            }
        }

        .planet {
            left: 5vw;
            top: 18vh;
            width: 120px
        }

        .satellite {
            right: 5vw;
            top: 14vh;
            width: 110px
        }

        .rocket {
            right: 10vw;
            bottom: 12vh;
            width: 120px
        }

        .ufo {
            left: 10vw;
            bottom: 8vh;
            width: 120px
        }

        @media (max-width: 720px) {
            .header-title {
                text-align: left;
                letter-spacing: .06em;
                font-size: 14px;
            }

            .planet {
                left: 2vw;
                top: 12vh;
                width: 90px
            }

            .satellite {
                right: 2vw;
                top: 8vh;
                width: 82px
            }

            .rocket {
                right: 4vw;
                bottom: 10vh;
                width: 86px
            }

            .ufo {
                left: 3vw;
                bottom: 6vh;
                width: 86px
            }
        }

        /* トースト */
        .toast {
            position: fixed;
            left: 50%;
            bottom: 18px;
            transform: translateX(-50%);
            background: rgba(15, 30, 60, .92);
            color: #fff;
            padding: 10px 14px;
            border: 1px solid rgba(255, 255, 255, .18);
            border-radius: 10px;
            opacity: 0;
            transition: .25s;
            z-index: 6;
            font-weight: 700
        }

        .toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(-6px)
        }

        /* 動きを苦手とする人向け */
        @media (prefers-reduced-motion: reduce) {

            .stars,
            .stars2,
            .stars3,
            .float-slow,
            .float-slower,
            .spin-slow {
                animation: none !important
            }
        }

        /* 追加：ジャンル選択UI */
        .scope-box {
            margin-top: 18px;
            border: none;
            border-radius: 0;
            padding: 0;
            background: transparent
        }

        .scope-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px
        }

        .scope-col {
            min-height: 120px;
            border: 1px dashed rgba(255, 255, 255, .18);
            border-radius: 10px;
            padding: 10px;
            overflow: auto
        }

        .scope-col.is-active {
            background: rgba(255, 224, 138, .08);
            border-color: rgba(255, 209, 102, .42);
        }

        .scope-col h3 {
            margin: .2rem 0 .6rem;
            font-size: 15px;
            opacity: .9
        }

        .scope-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-wrap: wrap;
            gap: .5rem
        }

        .scope-list li {
            display: flex;
            align-items: center;
            gap: .5rem
        }

        /* 階層リストのリンク色を宇宙テーマの文字色に */
        .scope-list a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, .18);
            background: rgba(255, 255, 255, .08);
            color: var(--space-ink);
            text-decoration: none;
        }

        .scope-list a:hover {
            filter: brightness(1.08);
        }

        .scope-list a.is-active {
            font-weight: 900;
            border-color: rgba(255, 209, 102, .72);
            background: linear-gradient(180deg, #ffe08a, #ffbf1f);
            color: #231900;
        }

        .scope-ids {
            grid-column: 1 / -1;
        }

        /* チェックリスト */
        .ids-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: .35rem
        }

        .id-item {
            display: flex;
            align-items: center;
            gap: .45rem;
            padding: .35rem .5rem;
            border: 1px solid rgba(255, 255, 255, .1);
            border-radius: 8px
        }

        .id-item.is-picked {
            background: rgba(255, 224, 138, .12);
            border-color: rgba(255, 209, 102, .55);
        }

        .id-item input {
            transform: translateY(1px)
        }

        /* モバイル最適化 */
        @media (max-width:720px) {
            .scope-grid {
                grid-template-columns: 1fr
            }
        }

        /* 追加：リセット（ゴースト風） */
        .reset-button {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, .35);
            color: var(--space-ink);
            padding: 6px 12px;
            border-radius: 10px;
            font-weight: 700;
        }

        .reset-button:hover {
            filter: brightness(1.15)
        }

        .reset-button:disabled {
            opacity: .45;
            cursor: not-allowed
        }

        .picked-summary-row {
            margin-top: 14px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }

        .picked-summary-main {
            flex: 1 1 420px;
            min-width: 0;
        }

        .picked-summary-head {
            display: flex;
            align-items: center;
            gap: .5rem;
            flex-wrap: wrap;
        }

        .picked-summary-actions {
            flex: 0 0 auto;
            display: flex;
            justify-content: flex-end;
        }

        .battle-create-btn {
            min-height: 40px;
            padding: 7px 22px;
            font-size: 1.5rem;
        }

        .picked-view {
            display: flex;
            gap: .4rem;
            flex-wrap: wrap;
            margin-top: .4rem;
            align-items: center;
        }

        .picked-label {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 12px;
            background: rgba(255, 255, 255, .08);
            border: 1px solid rgba(255, 255, 255, .16);
            font-size: 13px;
            font-weight: 800;
            color: var(--space-ink);
            white-space: nowrap;
        }

        .picked-label.is-empty {
            opacity: .7;
        }

        @media (max-width: 720px) {
            .picked-summary-actions {
                width: 100%;
                justify-content: flex-end;
            }
        }

        /* 入力フィールドに消去ボタンを重ねるためのラッパー */
        .input-wrap {
            position: relative;
            width: 100%;
            max-width: 520px;
            /* 既存の space-input と同じ上限 */
        }

        /* 右端にボタンが重なるので、右パディングを少し広げる */
        .input-wrap .space-input {
            padding-right: 2.4rem;
            width: 100%;
            box-sizing: border-box;
        }

        /* ×ボタン */
        .clear-input {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, .25);
            background: rgba(255, 255, 255, .12);
            color: #fff;
            font-weight: 900;
            line-height: 1;
            display: none;
            /* 値が入った時だけ表示 */
            cursor: pointer;
        }

        /* 値あり状態で表示 */
        .input-wrap.has-value .clear-input {
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .clear-input:hover {
            filter: brightness(1.12);
        }

        .clear-input:active {
            transform: translateY(-50%) scale(.96);
        }
    </style>
</head>

<body class="space">
    <header class="space-header">
        <div class="header-title">推し問バトル – 宇宙ルーム作成</div>
        <a class="header-back" href="index.php">トップに戻る</a>
    </header>

    <main class="space-scene">
        <div class="stars"></div>
        <div class="stars2"></div>
        <div class="stars3"></div>

        <!-- 惑星 -->
        <svg class="deco planet float-slow" viewBox="0 0 200 200" aria-hidden="true">
            <defs>
                <radialGradient id="g1" cx="50%" cy="50%">
                    <stop offset="0%" stop-color="#ffe6a3" />
                    <stop offset="70%" stop-color="#ff9f6e" />
                    <stop offset="100%" stop-color="#c464ff" />
                </radialGradient>

                <!-- 前面に見せたい“下側”だけを通すクリップ（必要なら y を微調整） -->
                <clipPath id="ringFrontClip">
                    <!-- 下半分だけ表示：y を 110 にすると輪の中心と揃います -->
                    <rect x="0" y="110" width="200" height="90" />
                </clipPath>
            </defs>

            <!-- 1) 後ろ側の輪（背面） -->
            <ellipse cx="100" cy="110" rx="88" ry="22"
                fill="none" stroke="rgba(255,255,255,.45)" stroke-width="4" />

            <!-- 2) 惑星（前面） -->
            <circle cx="100" cy="100" r="60" fill="url(#g1)" />

            <!-- 3) 前面に見せたい下側の輪（前面＋下半分のみ） -->
            <ellipse cx="100" cy="110" rx="88" ry="22"
                fill="none" stroke="rgba(255,255,255,.85)" stroke-width="4"
                clip-path="url(#ringFrontClip)" />
        </svg>
        <!-- 人工衛星 -->
        <svg class="deco satellite float-slower spin-slow" viewBox="0 0 200 200" aria-hidden="true">
            <rect x="92" y="80" width="16" height="40" rx="3" fill="#9ec7ff" stroke="#dff1ff" stroke-width="2" />
            <rect x="40" y="92" width="50" height="16" rx="3" fill="#b6d4ff" stroke="#e7f2ff" stroke-width="2" />
            <rect x="110" y="92" width="50" height="16" rx="3" fill="#b6d4ff" stroke="#e7f2ff" stroke-width="2" />
        </svg>
        <!-- ロケット -->
        <svg class="deco rocket float-slow" viewBox="0 0 200 200" aria-hidden="true">
            <g transform="translate(100,120) rotate(-30)">
                <path d="M0,-60 C20,-40 20,30 0,50 C-20,30 -20,-40 0,-60Z" fill="#ffffff" />
                <circle cx="0" cy="-30" r="9" fill="#69f0ff" />
                <path d="M-20,20 L0,10 L20,20 L0,40 Z" fill="#ff6f6f" />
                <path d="M0,48 Q-10,70 0,92 Q10,70 0,48Z" fill="url(#flame)" />
            </g>
            <defs>
                <linearGradient id="flame" x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0" stop-color="#fff59d" />
                    <stop offset=".6" stop-color="#ff8a65" />
                    <stop offset="1" stop-color="#ff5252" stop-opacity=".85" />
                </linearGradient>
            </defs>
        </svg>
        <!-- UFO -->
        <svg class="deco ufo float-slower" viewBox="0 0 240 200" aria-hidden="true">
            <ellipse cx="120" cy="120" rx="90" ry="22" fill="#6de0ff" opacity=".35" />
            <ellipse cx="120" cy="90" rx="70" ry="22" fill="#8be3ff" stroke="#dff9ff" stroke-width="2" />
            <circle cx="120" cy="74" r="18" fill="#c7fffc" stroke="#eaffff" />
            <circle cx="112" cy="72" r="4" />
            <circle cx="128" cy="72" r="4" />
        </svg>

        <section class="card">
            <?php if ($error): ?><p style="color:#ff8080;font-weight:700"><?= $error ?></p><?php endif; ?>

            <?php if ($created_urls): ?>
                <h1>バトル部屋（<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>）が作成されました！</h1>
                <?php if (!empty($selected_themes)): ?>
                    <div class="quiz-main">
                        <div class="quiz-main-label">今回出題するクイズ</div>
                        <div class="quiz-main-titles">
                            <?php foreach ($selected_themes as $t): ?>
                                <span class="quiz-main-title">
                                    <?= htmlspecialchars((string)$t['title'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="quiz-main">
                        <div class="quiz-main-label">今回出題するクイズ</div>
                        <div class="quiz-main-titles">
                            <span class="quiz-main-title">テーマ未選択</span>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="join-area">
                    <div class="hint">参加URL（利用者に配布）：</div>
                    <p class="join-url-wrap"><a class="join-url" href="<?= htmlspecialchars($created_urls['join'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($created_urls['join'], ENT_QUOTES, 'UTF-8') ?></a></p>
                    <p class="copy-link-wrap">
                        <a href="#" class="copy-link" data-copy="<?= htmlspecialchars($created_urls['join'], ENT_QUOTES) ?>">
                            <i class="far fa-copy" aria-hidden="true"></i>
                            <span class="sr-only">URLをコピー</span> このURLをコピーする
                        </a>
                    </p>
                </div>
                <div class="qr-share-row">
                    <div class="qr"><img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=<?= urlencode($created_urls['join']) ?>" alt="参加用QRコード"></div>
                    <div class="share-actions">
                        <a
                            href="#"
                            class="share-button"
                            data-share-url="<?= htmlspecialchars($created_urls['join'], ENT_QUOTES, 'UTF-8') ?>"
                            data-share-title="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>">
                            <i class="fas fa-share-alt" aria-hidden="true"></i>
                            共有
                        </a>
                    </div>
                </div>

            <?php else: ?>
                <h2>バトル部屋の作成！</h2>
                <form method="post" id="createForm">
                    <div class="form-row">
                        <label for="battleTitle">バトル名：</label>
                        <div class="input-wrap">
                            <input id="battleTitle" class="space-input" type="text" name="title"
                                required placeholder="例）クイズバトル"
                                value="<?= htmlspecialchars($prefillTitle, ENT_QUOTES, 'UTF-8') ?>"
                                autocomplete="off">
                            <button type="button" class="clear-input" id="clearTitle" aria-label="入力をクリア">×</button>
                        </div>
                    </div>

                    <?php if ($param_c1 !== null && $param_c1 !== false && $param_c2 !== null && $param_c2 !== false && $param_qid !== null && $param_qid !== false): ?>
                        <!-- 推し問から単一指定が来ている場合は hidden で維持 -->
                        <input type="hidden" name="cate1" value="<?= (int)$param_c1 ?>">
                        <input type="hidden" name="cate2" value="<?= (int)$param_c2 ?>">
                        <input type="hidden" name="id" value="<?= (int)$param_qid ?>">
                    <?php endif; ?>

                    <!-- ▼ 追加：出題ジャンル選択 -->
                    <div class="scope-box">
                        <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
                            <h3 style="margin:0;font-size:16px">出題ジャンルの選択</h3>
                        </div>

                        <div class="scope-grid" style="margin-top:8px">
                            <div class="scope-col">
                                <h3>① 分野</h3>
                                <ul id="c1List" class="scope-list"></ul>
                            </div>

                            <div class="scope-col">
                                <h3>② ジャンル</h3>
                                <ul id="c2List" class="scope-list"></ul>
                            </div>

                            <div class="scope-col scope-ids">
                                <h3>③ タイトル</h3>
                                <div id="idsList" class="ids-grid"></div>
                            </div>
                        </div>

                        <!-- ここに選択結果（見えるタグ一覧） -->
                        <div class="picked-summary-row">
                            <div class="picked-summary-main">
                                <div class="picked-summary-head">
                                    <strong>選択済み：</strong>
                                    <button type="button" id="resetPickedBtn" class="space-button reset-button">
                                        リセット
                                    </button>
                                </div>
                                <div id="chosen" class="picked-view"></div>
                            </div>
                            <div class="picked-summary-actions">
                                <button class="qb-round-yellow-btn battle-create-btn" type="submit">作成</button>
                            </div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </main>
    <div id="copyToast" class="toast" role="status" aria-live="polite"></div>

    <script>
        function showToast(message) {
            const toast = document.getElementById('copyToast');
            if (!toast) return;
            toast.textContent = message;
            toast.classList.add('show');
            clearTimeout(showToast.timer);
            showToast.timer = setTimeout(() => {
                toast.classList.remove('show');
            }, 1800);
        }

        async function copyText(text) {
            if (!text) return false;

            try {
                if (navigator.clipboard?.writeText && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                    return true;
                }
            } catch (err) {}

            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();

            let ok = false;
            try {
                ok = document.execCommand('copy');
            } catch (err) {
                ok = false;
            }

            document.body.removeChild(textarea);
            return ok;
        }

        document.querySelector('.copy-link')?.addEventListener('click', async e => {
            e.preventDefault();
            const text = e.currentTarget.dataset.copy || '';
            const ok = await copyText(text);
            showToast(ok ? 'URLをコピーしました' : 'コピーできませんでした');
        });

        document.querySelector('.share-button')?.addEventListener('click', async e => {
            e.preventDefault();
            const el = e.currentTarget;
            const url = el.dataset.shareUrl || '';
            const battleTitle = el.dataset.shareTitle || 'クイズバトル';
            const shareData = {
                title: battleTitle,
                text: `${battleTitle} に参加しよう`,
                url
            };

            if (navigator.share) {
                try {
                    await navigator.share(shareData);
                    return;
                } catch (err) {
                    if (err && err.name === 'AbortError') return;
                }
            }

            const ok = await copyText(url);
            showToast(ok ? '共有用URLをコピーしました' : '共有できませんでした');
        });

        const API_BASE = 'api/head_api.php'; // ← 先頭で宣言しておく

        // ▼ ジャンル選択UI ===========================================
        const c1List = document.getElementById('c1List');
        const c2List = document.getElementById('c2List');
        const idsList = document.getElementById('idsList');
        const chosen = document.getElementById('chosen');
        const form = document.getElementById('createForm');
        const resetBtn = document.getElementById('resetPickedBtn');

        let selC1 = null;
        let selC2 = null;

        // 選択済みコレクション（key: "c1-c2-id", value: title）
        const picked = new Map();

        // 選択済みの表示タグと hidden input を同期
        function renderPicked() {
            chosen.innerHTML = '';
            // 既存 hidden を全削除
            [...form.querySelectorAll('input[name="scopes[]"]')].forEach(el => el.remove());
            idsList.querySelectorAll('.id-item').forEach(item => {
                const cb = item.querySelector('input[type="checkbox"]');
                item.classList.toggle('is-picked', !!cb?.checked);
            });
            idsList.closest('.scope-col')?.classList.toggle('is-active', picked.size > 0);

            if (picked.size === 0) {
                // 何もない時はボタン無効化
                if (resetBtn) resetBtn.disabled = true;
                return;
            }
            if (resetBtn) resetBtn.disabled = false;

            picked.forEach((title, key) => {
                const tag = document.createElement('span');
                tag.textContent = title;
                tag.className = 'picked-label';
                tag.title = 'クリックで削除';
                tag.style.cursor = 'pointer';
                tag.addEventListener('click', () => {
                    picked.delete(key);
                    const cb = idsList.querySelector(`input[data-key="${key}"]`);
                    if (cb) cb.checked = false;
                    renderPicked();
                });
                chosen.appendChild(tag);

                const hid = document.createElement('input');
                hid.type = 'hidden';
                hid.name = 'scopes[]';
                hid.value = key;
                form.appendChild(hid);
            });
        }

        // ▼ リセット処理
        function resetPicked() {
            // 画面上のチェックを全部外す
            idsList.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });
            // データを空に
            picked.clear();
            // hidden を削除 & 表示更新
            renderPicked();
        }

        // 初期化：ボタンにイベント付与＆最初は無効
        if (resetBtn) {
            resetBtn.addEventListener('click', resetPicked);
            resetBtn.disabled = true;
        }

        // 大分類ロード
        async function loadC1() {
            c1List.innerHTML = '<li>読み込み中…</li>';
            const r = await fetch(`${API_BASE}?mode=c1`);
            const js = await r.json().catch(() => ({
                ok: false
            }));
            if (!js.ok) {
                c1List.innerHTML = '<li>取得失敗</li>';
                return;
            }
            c1List.innerHTML = '';
            js.data.forEach(row => {
                const li = document.createElement('li');
                const a = document.createElement('a');
                a.href = '#';
                a.textContent = `${row.title}`;
                a.addEventListener('click', (e) => {
                    e.preventDefault();
                    selC1 = row.cate1;
                    selC2 = null;
                    highlightC1();
                    loadC2();
                    idsList.innerHTML = '';
                });
                li.appendChild(a);
                li.dataset.c1 = row.cate1;
                c1List.appendChild(li);
            });
        }

        function highlightC1() {
            [...c1List.children].forEach(li => {
                const active = (parseInt(li.dataset.c1, 10) === parseInt(selC1 || -1, 10));
                const a = li.querySelector('a');
                if (!a) return;
                a.classList.toggle('is-active', active);
            });
            c1List.closest('.scope-col')?.classList.toggle('is-active', !!selC1);
            [...c2List.children].forEach(li => li.remove());
            c2List.closest('.scope-col')?.classList.remove('is-active');
        }

        async function loadC2() {
            if (!selC1) {
                c2List.innerHTML = '<li>大分類を選んでください</li>';
                return;
            }
            c2List.innerHTML = '<li>読み込み中…</li>';
            const r = await fetch(`${API_BASE}?mode=c2&cate1=${encodeURIComponent(selC1)}`);
            const js = await r.json().catch(() => ({
                ok: false
            }));
            if (!js.ok) {
                c2List.innerHTML = '<li>取得失敗</li>';
                return;
            }
            c2List.innerHTML = '';
            js.data.forEach(row => {
                const li = document.createElement('li');
                const a = document.createElement('a');
                a.href = '#';
                a.textContent = `${row.title}`;
                a.addEventListener('click', (e) => {
                    e.preventDefault();
                    selC2 = row.cate2;
                    highlightC2();
                    loadIds();
                });
                li.appendChild(a);
                li.dataset.c2 = row.cate2;
                c2List.appendChild(li);
            });
        }

        function highlightC2() {
            [...c2List.children].forEach(li => {
                const active = (parseInt(li.dataset.c2, 10) === parseInt(selC2 || -1, 10));
                const a = li.querySelector('a');
                if (!a) return;
                a.classList.toggle('is-active', active);
            });
            c2List.closest('.scope-col')?.classList.toggle('is-active', !!selC2);
        }

        async function loadIds() {
            if (!selC1 || !selC2) {
                idsList.innerHTML = '';
                return;
            }
            idsList.innerHTML = '読み込み中…';
            const r = await fetch(`${API_BASE}?mode=ids&cate1=${encodeURIComponent(selC1)}&cate2=${encodeURIComponent(selC2)}`);
            const js = await r.json().catch(() => ({
                ok: false
            }));
            if (!js.ok) {
                idsList.innerHTML = '取得失敗';
                return;
            }
            idsList.innerHTML = '';
            js.data.forEach(row => {
                const key = `${selC1}-${selC2}-${row.id}`;
                const title = row.title; // ここでタイトルを保持
                const div = document.createElement('label');
                div.className = 'id-item';
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.value = key;
                cb.dataset.key = key;
                cb.checked = picked.has(key);
                cb.addEventListener('change', () => {
                    if (cb.checked) picked.set(key, title);
                    else picked.delete(key);
                    div.classList.toggle('is-picked', cb.checked);
                    renderPicked();
                });
                const span = document.createElement('span');
                span.textContent = `${row.id}: ${title}`;
                div.appendChild(cb);
                div.appendChild(span);
                idsList.appendChild(div);
                div.classList.toggle('is-picked', cb.checked);
            });

        }

        // 初期ロード
        loadC1();

        (function() {
            const titleInput = document.getElementById('battleTitle');
            const clearBtn = document.getElementById('clearTitle');
            const wrap = clearBtn?.closest('.input-wrap');

            if (!titleInput || !clearBtn || !wrap) return;

            const sync = () => {
                wrap.classList.toggle('has-value', !!titleInput.value.trim());
            };

            // 初期状態（デフォルト名が入っているので most likely 表示）
            sync();

            // 入力のたびに表示/非表示を切り替え
            titleInput.addEventListener('input', sync);

            // クリックで消去→inputイベントを発火→フォーカス維持
            clearBtn.addEventListener('click', () => {
                titleInput.value = '';
                titleInput.dispatchEvent(new Event('input', {
                    bubbles: true
                }));
                titleInput.focus();
            });
        })();
    </script>
</body>

</html>
