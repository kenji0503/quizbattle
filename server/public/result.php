<?php
// 結果発表（3問終了後）
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/logs/logger.php';
require_once __DIR__ . '/common/define.php';
require_once __DIR__ . '/common/battle_common.php'; // dbConnectPDO() は上のconfigで繋がる前提

$log = Logger::getInstance();
$log->debug('** result.php start');

$pdo = dbConnectPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// bid/gid はセッション優先、無ければ GET から
$gid = (int)($_SESSION['gid'] ?? ($_GET['gid'] ?? 0));
$bid = (int)($_SESSION['bid'] ?? ($_GET['bid'] ?? 0));
$uid  = (int)($_SESSION['uid'] ?? 0);
$selfUid = $uid; // ← セッション本人のUIDを退避（以後はこれを使う）

if ($gid <= 0 || $bid <= 0) {
    http_response_code(400);
    die('invalid bid/gid');
}

$st = $pdo->prepare("SELECT bnum, phase, switch_at FROM qb_battle_state WHERE bid=:bid AND gid=:gid");
$st->execute([':bid' => $bid, ':gid' => $gid]);
$row = $st->fetch(PDO::FETCH_ASSOC);

$log->debug('[RESULT_ENTER] uid=' . $uid . ' gid=' . $gid . ' bid=' . $bid .
    ' phase=' . ($row['phase'] ?? 'null') . ' bnum=' . ($row['bnum'] ?? 'null') .
    ' ref=' . ($_SERVER['HTTP_REFERER'] ?? '-') . ' ua=' . ($_SERVER['HTTP_USER_AGENT'] ?? '-'));

if (!$row) {
    die('state not found');
}
$nowMs = (int)floor(microtime(true) * 1000);
$maxSt = $pdo->prepare("SELECT MAX(order_no) FROM qb_battle_lineup WHERE bid=?");
$maxSt->execute([$bid]);
$maxOrder = (int)($maxSt->fetchColumn() ?? 0);

$isFinished = ((int)$row['phase'] === BATTLE_PHASE_FINISHED)
    || ((int)$row['bnum'] >= $maxOrder && $nowMs >= (int)$row['switch_at']);
if (!$isFinished) {
    header("Location: start.php?gid={$gid}&bid={$bid}");
    exit;
}

function format_elapsed_hundredths(?int $elapsedMs): string
{
    if ($elapsedMs === null || $elapsedMs < 0) {
        return '—';
    }
    return number_format($elapsedMs / 1000, 2, '.', '') . '秒';
}

// 3問（bnum=1..3）を取得
$lineupHasSchedule = battle_has_lineup_schedule_columns($pdo);
$scheduleSelect = $lineupHasSchedule
    ? ', l.q_start_at_ms, l.reveal_at_ms, l.switch_at_ms'
    : ', 0 AS q_start_at_ms, 0 AS reveal_at_ms, 0 AS switch_at_ms';

$qStmt = $pdo->prepare("
   SELECT
     l.order_no AS bnum, l.cate1, l.cate2, l.id, l.num,
     m.mondai, m.kaito, m.qa, m.qb, m.qc, m.qd
     {$scheduleSelect}
   FROM qb_battle_lineup l
   JOIN qb_question_bank m
     ON m.cate1=l.cate1 AND m.cate2=l.cate2 AND m.qid=l.id AND m.qnum=l.num AND m.del=0
   WHERE l.bid=:bid
   ORDER BY l.order_no ASC
 ");
$qStmt->execute([':bid' => $bid]);
$questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

if (!$questions) {
    die('このバトルには問題がありません。');
}

// 参加者一覧（未回答者も結果へ出す）
$pStmt = $pdo->prepare("
  SELECT uid, name
    FROM qb_battle_participants
   WHERE bid = :bid AND gid = :gid
   ORDER BY joined_at ASC, participant_id ASC
");
$pStmt->execute([':bid' => $bid, ':gid' => $gid]);
$participantRows = $pStmt->fetchAll(PDO::FETCH_ASSOC);

// 参加者の解答（全問分）
$bStmt = $pdo->prepare("
  SELECT b.uid, b.cate1, b.cate2, b.id, b.num, b.sentaku, b.buzzed_at,
         CAST(ROUND(UNIX_TIMESTAMP(b.buzzed_at) * 1000) AS SIGNED) AS buzzed_at_ms,
         COALESCE(p.name, CONCAT('Player#', b.uid)) AS name
  FROM qb_buzzes b
  LEFT JOIN qb_battle_participants p
    ON p.bid = b.bid AND p.gid = b.gid AND p.uid = b.uid
  WHERE b.bid=:bid AND b.gid=:gid
  ORDER BY b.cate1, b.cate2, b.id, b.num, b.buzzed_at ASC
");
$bStmt->execute([':bid' => $bid, ':gid' => $gid]);
$buzzes = $bStmt->fetchAll(PDO::FETCH_ASSOC);

// --- 事実確認用の最小ログ（キー不一致の有無を確認） ---
// ① 出題キー集合
$qKeys = array_map(function ($q) {
    return "{$q['cate1']}-{$q['cate2']}-{$q['id']}-{$q['num']}";
}, $questions);
$qKeys = array_values(array_unique($qKeys));

// ② 回答側キー集合
$bKeys = [];
foreach ($buzzes as $b) {
    $bKeys[] = "{$b['cate1']}-{$b['cate2']}-{$b['id']}-{$b['num']}";
}
$bKeys = array_values(array_unique($bKeys));

// ③ 差分をログ（先頭5件だけ）
$missingInQ = array_values(array_diff($bKeys, $qKeys)); // buzzにはあるが出題に無い
$missingInB = array_values(array_diff($qKeys, $bKeys)); // 出題にはあるがbuzzに無い

$log->debug('[RESULT_DIAG] qKeys=' . count($qKeys) . ' bKeys=' . count($bKeys)
    . ' diff:buzz->q=' . json_encode(array_slice($missingInQ, 0, 5), JSON_UNESCAPED_UNICODE)
    . ' diff:q->buzz=' . json_encode(array_slice($missingInB, 0, 5), JSON_UNESCAPED_UNICODE));

// 参考：差分が出た場合、最初の1キーに紐づくbuzz行を少しだけ出す（任意）
if (!empty($missingInQ)) {
    $probe = $missingInQ[0];
    $cnt = 0;
    foreach ($buzzes as $b) {
        $k = "{$b['cate1']}-{$b['cate2']}-{$b['id']}-{$b['num']}";
        if ($k === $probe && $cnt < 3) {
            $log->debug('[RESULT_DIAG_SAMPLE] key=' . $probe . ' uid=' . $b['uid'] . ' sentaku=' . $b['sentaku'] . ' at=' . $b['buzzed_at']);
            $cnt++;
        }
    }
}
// --- /最小ログ ここまで ---

// インデックス化
$qByKey = [];    // "c1-c2-id-num" => ['bnum'=>..,'kaito'=>.., ...]
$qOrder = [];    // bnum => key
foreach ($questions as $q) {
    $key = "{$q['cate1']}-{$q['cate2']}-{$q['id']}-{$q['num']}";
    $qByKey[$key] = $q;
    $qOrder[(int)$q['bnum']] = $key;
}

// 集計用
$users = [];        // uid => name
$perUser = [];      // uid => ['correct'=>0,'first'=>0,'pos_sum'=>0,'elapsed_sum'=>0,'elapsed_count'=>0]
$perQuestionByUser = []; // uid => [bnum => ['answer'=>..., 'ok'=>..., 'elapsed_ms'=>..., 'pos'=>...]]

foreach ($participantRows as $participantRow) {
    $participantUid = (int)$participantRow['uid'];
    $participantName = (string)($participantRow['name'] ?? '');
    if ($participantUid > 0 && $participantName !== '' && !isset($users[$participantUid])) {
        $users[$participantUid] = $participantName;
    }
}

// 問題ごとに並び順をつけて採点
$grouped = []; // key => [rows...]
foreach ($buzzes as $b) {
    $key = "{$b['cate1']}-{$b['cate2']}-{$b['id']}-{$b['num']}";
    $grouped[$key][] = $b;
    $users[(int)$b['uid']] = $b['name'];
}

// 各問題で pos を振って正誤判定（1問につき1人1回に集約）
$USE_FINAL_ANSWER = true; // 採点は「最終選択」。最初で採点したい場合は false に。

foreach ($grouped as $key => $rows) {
    if (!isset($qByKey[$key])) continue;

    $bnum  = (int)$qByKey[$key]['bnum'];
    $kaito = (string)$qByKey[$key]['kaito']; // 仕様どおり 'A'|'B'|'C'|'D' が入る前提
    $qStartAtMs = (int)($qByKey[$key]['q_start_at_ms'] ?? 0);

    // uidごとに「最初の押し（順位用）」と「最後の選択（採点用）」を抽出
    $firstByUid = []; // uid => 最初に押した行
    $lastByUid  = []; // uid => 最後に押した行
    foreach ($rows as $r) {
        $uid = (int)$r['uid'];
        $users[$uid] = $r['name'];            // 表示用に保持
        if (!isset($firstByUid[$uid])) {
            $firstByUid[$uid] = $r;           // $rowsはbuzzed_at昇順で渡ってくる前提
        }
        $lastByUid[$uid] = $r;                // 最後の行で更新
    }

    // 順位は「最初の押し」の時刻昇順
    $uniq = array_values($firstByUid);
    usort($uniq, function ($a, $b) {
        $t = strcmp($a['buzzed_at'], $b['buzzed_at']);
        return ($t !== 0) ? $t : ($a['uid'] <=> $b['uid']); // 同時刻時の安定化
    });

    $pos = 0;
    $firstCorrectAwarded = false; // その問題の最初の正解者だけ +1

    foreach ($uniq as $rFirst) {
        $uid = (int)$rFirst['uid'];
        $pos++;

        // 採点は「最終選択」か「最初の選択」をスイッチ
        $ans = $USE_FINAL_ANSWER ? (string)$lastByUid[$uid]['sentaku'] : (string)$rFirst['sentaku'];
        $ok  = ($ans === $kaito); // A/B/C/D 同士の厳密比較のみ
        $buzzedAtMs = isset($rFirst['buzzed_at_ms']) ? (int)$rFirst['buzzed_at_ms'] : null;
        $elapsedMs = ($qStartAtMs > 0 && $buzzedAtMs !== null) ? max(0, $buzzedAtMs - $qStartAtMs) : null;

        // 個人集計（この問題について1回だけ）
        if (!isset($perUser[$uid])) {
            $perUser[$uid] = ['correct' => 0, 'first' => 0, 'pos_sum' => 0, 'elapsed_sum' => 0, 'elapsed_count' => 0];
        }
        if ($ok) {
            $perUser[$uid]['correct']++;
            if (!$firstCorrectAwarded) {
                $perUser[$uid]['first']++;
                $firstCorrectAwarded = true;
            }
        }
        $perUser[$uid]['pos_sum'] += $pos;
        if ($elapsedMs !== null) {
            $perUser[$uid]['elapsed_sum'] += $elapsedMs;
            $perUser[$uid]['elapsed_count']++;
        }
        $perQuestionByUser[$uid][$bnum] = [
            'answer' => $ans,
            'ok' => $ok,
            'elapsed_ms' => $elapsedMs,
            'pos' => $pos,
        ];
    }
}

// “解答なし”の参加者も載せたい場合は qb_user から拾って追加してもOK（任意）
// ここでは「回答した人だけ」を表示対象にします。

// ランキング作成：正解 desc, pos_sum asc, name asc
$rank = [];
foreach ($users as $uid => $name) {
    $c = $perUser[$uid]['correct'] ?? 0;
    $f = $perUser[$uid]['first']   ?? 0;
    $s = $perUser[$uid]['pos_sum'] ?? 0;
    $elapsedSum = $perUser[$uid]['elapsed_sum'] ?? 0;
    $elapsedCount = $perUser[$uid]['elapsed_count'] ?? 0;
    $avgElapsedMs = $elapsedCount > 0 ? (int)round($elapsedSum / $elapsedCount) : null;
    $rank[] = [
        'uid' => $uid,
        'name' => $name,
        'correct' => $c,
        'first' => $f,
        'pos_sum' => $s,
        'avg_elapsed_ms' => $avgElapsedMs,
        'avg_elapsed_label' => format_elapsed_hundredths($avgElapsedMs),
    ];
}
usort($rank, function ($a, $b) {
    if ($a['correct'] !== $b['correct']) return ($b['correct'] - $a['correct']); // desc
    if ($a['pos_sum'] !== $b['pos_sum']) return ($a['pos_sum'] - $b['pos_sum']); // asc
    return strcmp($a['name'], $b['name']);
});

// 自分の順位・スコア
$myRow = null;
$myRank = null;
foreach ($rank as $i => $row) {
    if ($row['uid'] === $selfUid) {
        $myRow = $row;
        $myRank = $i + 1;
        break;
    }
}

$selfResults = [];
foreach ($questions as $q) {
    $bnum = (int)$q['bnum'];
    $detail = $perQuestionByUser[$selfUid][$bnum] ?? null;
    $answerLabel = $detail['answer'] ?? '';
    switch ($answerLabel) {
        case 'A':
            $answerText = (string)($q['qa'] ?? '');
            break;
        case 'B':
            $answerText = (string)($q['qb'] ?? '');
            break;
        case 'C':
            $answerText = (string)($q['qc'] ?? '');
            break;
        case 'D':
            $answerText = (string)($q['qd'] ?? '');
            break;
        default:
            $answerText = '未回答';
            break;
    }

    $selfResults[] = [
        'bnum' => $bnum,
        'cate1' => (int)$q['cate1'],
        'cate2' => (int)$q['cate2'],
        'id' => (int)$q['id'],
        'num' => (int)$q['num'],
        'mondai' => (string)$q['mondai'],
        'answer_label' => $answerLabel,
        'answer_text' => $answerText,
        'judge' => $detail ? (($detail['ok'] ?? false) ? '〇' : '×') : '—',
        'judge_class' => $detail ? (($detail['ok'] ?? false) ? 'ok' : 'ng') : 'muted',
        'elapsed_label' => format_elapsed_hundredths($detail['elapsed_ms'] ?? null),
    ];
}

$log->debug('[RESULT_SUMMARY] ' . json_encode($rank, JSON_UNESCAPED_UNICODE));

// 再戦用のリンク
$__noticeMessage = (isset($_GET['notice']) && $_GET['notice'] === 'ended')
    ? '既にバトルは終了しています。結果をご確認ください。'
    : null;

?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ja" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>結果発表</title>
    <link rel="stylesheet" href="css/battle.css">
    <link rel="stylesheet" href="css/ui-common.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        .container {
            width: min(1000px, 95%);
            margin: 24px auto;
        }

        .card {
            background: #2c2c4a;
            border: 3px solid #FFD700;
            border-radius: 12px;
            padding: 16px;
            margin-top: 20px;
            margin-bottom: 20px;
        }

        h2 {
            margin: 0 0 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #555;
            padding: 8px;
            text-align: center;
        }

        th {
            background: #FFD700;
            color: #000;
        }

        tr.uid {
            background: #4c4c81ff;
            font-weight: bold;
        }

        .ok {
            color: #7CFC00;
            font-weight: bold;
        }

        /* 〇 */
        .ng {
            color: #FF6B6B;
            font-weight: bold;
        }

        /* × */
        .muted {
            color: #bbb;
        }

        .matrix-mark {
            display: inline-block;
        }

        .matrix-time {
            display: block;
            margin-top: 4px;
            font-size: .82rem;
            font-weight: 700;
            color: #d9e2f4;
        }

        .big {
            font-size: 1.6rem;
            font-weight: 800;
        }

        .pc-only {
            display: inline;
        }

        .mobile-only {
            display: none;
        }

        .desktop-table {
            display: table;
            width: 100%;
        }

        .mobile-card-list {
            display: none;
        }

        .btn {
            display: inline-block;
            margin: .5rem .25rem;
            padding: .5rem 1rem;
            background: #FFD700;
            color: #000;
            border-radius: 6px;
            text-decoration: none;
        }

        .rank-mark-col {
            width: 44px;
            padding-left: 6px;
            padding-right: 6px;
        }

        .rank-mark-cell {
            width: 44px;
            text-align: center;
        }

        .rank-crown {
            display: inline-block;
            width: 24px;
            height: 24px;
            object-fit: contain;
            vertical-align: middle;
        }

        .mobile-result-card {
            background: #232345;
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 14px;
            padding: 12px;
            margin-bottom: 10px;
        }

        .mobile-rank-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 8px;
        }

        .mobile-rank-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1.6rem;
            font-weight: 900;
            color: #fff;
        }

        .mobile-rank-stats {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .mobile-stat {
            border-radius: 10px;
            background: rgba(255, 255, 255, .06);
            padding: 8px 10px;
            text-align: left;
        }

        .mobile-stat-label {
            display: block;
            color: #cfd7e7;
            font-size: 1.1rem;
            margin-bottom: 4px;
        }

        .mobile-stat-value {
            display: block;
            color: #fff;
            font-size: 1.4rem;
            font-weight: 800;
        }

        .mobile-history-card {
            background: #232345;
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 14px;
            padding: 12px;
            margin-bottom: 12px;
        }

        .mobile-history-card.ok {
            background: linear-gradient(180deg, rgba(42, 99, 180, .5), rgba(24, 42, 88, .92));
            border-color: rgba(116, 183, 255, .48);
        }

        .mobile-history-card.ng {
            background: linear-gradient(180deg, rgba(173, 54, 72, .48), rgba(89, 24, 37, .92));
            border-color: rgba(255, 138, 157, .42);
        }

        .mobile-history-card.muted {
            background: #232345;
        }

        .mobile-history-head {
            font-size: 1.6rem;
            font-weight: 900;
            color: #fff;
            margin-bottom: 10px;
        }

        .mobile-history-question {
            color: #fff;
            line-height: 1.6;
            margin-bottom: 10px;
        }

        .mobile-history-grid {
            display: grid;
            grid-template-columns: 88px minmax(0, 1fr);
            gap: 8px 10px;
            align-items: start;
        }

        .mobile-history-label {
            color: #cfd7e7;
            font-weight: 700;
            text-align: left;
        }

        .mobile-history-value {
            color: #fff;
            text-align: left;
            word-break: break-word;
        }

        /* 問題の評価用 */
        .rate-row {
            display: grid;
            grid-template-columns: 3rem 1fr auto;
            gap: 8px;
            align-items: center;
            border: 1px solid #555;
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 8px;
            background: #232345;
        }

        .rate-idx {
            text-align: center;
            font-weight: 700;
        }

        .rate-q {
            text-align: left;
        }

        .rate-actions .vote-btn {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .4rem .7rem;
            margin-left: .3rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 700;
        }

        .vote-good {
            background: #27ae60;
            color: #fff;
        }

        .vote-bad {
            background: #e74c3c;
            color: #fff;
        }

        .vote-btn[disabled] {
            opacity: .6;
            cursor: default;
        }

        .participant-row {
            grid-template-columns: 4.6rem 1fr auto;
        }

        .participant-row.is-self {
            border-color: rgba(105, 240, 255, .42);
            box-shadow: 0 0 0 1px rgba(105, 240, 255, .18) inset;
        }

        .participant-name {
            font-size: 1.9rem;
            font-weight: 800;
            color: #fff;
        }

        .participant-meta {
            margin-top: 6px;
            color: #d8dfef;
            font-size: 1.4rem;
            line-height: 1.7;
        }

        .participant-stats {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
        }

        .participant-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-width: 120px;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, .14);
            background: rgba(255, 255, 255, .08);
            color: #fff;
            font-weight: 800;
            justify-content: center;
        }

        .participant-pill strong {
            color: #ffd86b;
            font-size: 1.45rem;
        }

        /* 狭い画面ではボタンを縦積み＋幅いっぱいに */
        @media (max-width: 640px) {

            /* 行レイアウト：アクションを下段に落とす */
            .rate-row {
                grid-template-columns: 3rem 1fr;
                /* 右端の 'auto' 列を無くす */
                align-items: start;
            }

            .rate-actions {
                grid-column: 1 / -1;
                /* 下段へ（左右全体） */
                flex-direction: column;
                /* 縦積み */
                align-items: stretch;
                /* 幅いっぱい */
                gap: 8px;
                margin-top: 2px;
            }

            .rate-actions .vote-btn {
                width: 100%;
                margin-left: 0;
                /* 既存の margin-left を打ち消し */
            }

            .participant-row {
                grid-template-columns: 4.2rem 1fr;
            }

            .participant-stats {
                grid-column: 1 / -1;
                justify-content: flex-start;
                margin-top: 8px;
            }
        }


        .rate-note {
            font-size: .9rem;
            color: #bbb;
            margin: 6px 0 0;
        }

        .history-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 10px;
        }

        .history-table th,
        .history-table td {
            border: none;
        }

        .history-row td {
            background: #232345;
            vertical-align: middle;
        }

        .history-row td:first-child {
            border-radius: 10px 0 0 10px;
        }

        .history-row td:last-child {
            border-radius: 0 10px 10px 0;
        }

        .history-table .rate-actions {
            display: flex;
            justify-content: center;
            gap: .4rem;
            flex-wrap: wrap;
        }

        .mobile-history-card .rate-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .mobile-history-card .rate-actions .vote-btn {
            margin-left: 0;
            min-width: 0;
            justify-content: center;
            width: 100%;
        }

        /* 正解表示 */
        .rate-ans {
            font-size: .95rem;
            color: #ddd;
            margin-top: 4px;
        }

        .pill {
            display: inline-block;
            min-width: 1.4em;
            text-align: center;
            border: 1px solid #888;
            border-radius: 999px;
            padding: 0 .45em;
            margin-right: .35em;
            background: #111;
            font-weight: 700;
        }

        /* ヘッダバーを中央寄せ＆余白調整 */
        #topBar {
            position: sticky;
            /* 既存でstickyならそのまま */
            top: 0;
            z-index: 10;
            display: flex;
            justify-content: center;
            /* 中央寄せ */
            align-items: center;
            padding: 14px 16px;
            background: linear-gradient(90deg, #181a3a, #2a1a47 40%, #0b2846);
            border-bottom: 1px solid rgba(255, 255, 255, .15);
        }

        /* 見出しをドーンと */
        .score-head {
            margin: 0;
            text-align: center;
            font-weight: 900;
            letter-spacing: .02em;
            font-size: clamp(24px, 6vw, 48px);
            /* 画面に応じて可変 */
            line-height: 1.15;
            color: #69f0ff;
            /* 目立つネオン系 */
            text-shadow:
                0 0 8px rgba(105, 240, 255, .6),
                0 0 18px rgba(105, 240, 255, .35);
        }

        /* 成績のバッジをさらに強調（色はゴールド系に） */
        /* ベース（共通） */
        .rank-badge {
            display: inline-block;
            margin-left: .4rem;
            padding: .14em .5em;
            border-radius: .7em;
            background: linear-gradient(180deg, #ffe070, #ff9d3c);
            color: #231a00;
            border: 1px solid rgba(255, 224, 112, .8);
            box-shadow:
                0 2px 10px rgba(255, 200, 80, .35),
                inset 0 1px 0 rgba(255, 255, 255, .6);
            font-size: .92em;
            /* 見出しのサイズに対する相対 */
            font-weight: 900;
        }

        /* 1) NEON（暗所でも最強の可読性） */
        .rank-badge.-neon {
            background: #00f0ff;
            color: #06141a;
            box-shadow:
                0 0 12px rgba(0, 240, 255, .55),
                0 0 28px rgba(0, 240, 255, .35);
            border: 1px solid rgba(180, 255, 255, .9);
            text-shadow: none;
        }

        /* 2) FLAT HIGH-CONTRAST（軽量・くっきり） */
        .rank-badge.-flat {
            background: #ff4d6d;
            /* お好みで #ffb703 などに変更可 */
            color: #120207;
            border: 1px solid rgba(255, 255, 255, .55);
            box-shadow: 0 2px 8px rgba(0, 0, 0, .18);
            text-shadow: none;
        }

        /* 3) GLASS PILL（ガラス感：背景に馴染みつつ目立つ） */
        .rank-badge.-glass {
            background: linear-gradient(180deg, rgba(255, 255, 255, .28), rgba(255, 255, 255, .08));
            color: #fff;
            border: 1px solid rgba(255, 255, 255, .45);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, .55),
                0 6px 18px rgba(0, 0, 0, .25);
            backdrop-filter: blur(8px);
            text-shadow: 0 1px 2px rgba(0, 0, 0, .55);
        }

        /* 4) OUTLINE（背景が派手な見出しでも潰れない） */
        .rank-badge.-outline {
            background: transparent;
            color: #fff;
            border: 2px solid currentColor;
            text-shadow:
                -1px -1px 0 rgba(0, 0, 0, .35),
                1px -1px 0 rgba(0, 0, 0, .35),
                -1px 1px 0 rgba(0, 0, 0, .35),
                1px 1px 0 rgba(0, 0, 0, .35);
            box-shadow: none;
        }

        /* 5) RIBBON（リボン風。横長見出しに合う） */
        .rank-badge.-ribbon {
            position: relative;
            background: linear-gradient(180deg, #ffb703, #ff8f00);
            color: #2a1400;
            border: 1px solid rgba(255, 230, 160, .9);
            box-shadow: 0 3px 10px rgba(0, 0, 0, .25);
        }

        .rank-badge.-ribbon::after {
            content: "";
            position: absolute;
            right: -.55em;
            top: 50%;
            transform: translateY(-50%);
            border: .5em solid transparent;
            border-left-color: #cc7300;
            /* 折返し色 */
        }

        /* 6) METAL（メタルだが暗部を抑え、明部多め） */
        .rank-badge.-metal {
            background:
                linear-gradient(180deg, #fff7c8 0%, #ffe37a 42%, #ffc44a 60%, #ffdd8a 100%);
            color: #3b2900;
            border: 1px solid #fff1b0;
            box-shadow:
                0 2px 12px rgba(255, 200, 80, .35),
                inset 0 1px 0 rgba(255, 255, 255, .85);
            text-shadow: 0 1px 0 rgba(255, 255, 255, .4);
        }

        /* 小さめ画面でのタップしやすさ */
        @media (max-width:640px) {
            .rank-badge {
                padding: .24em .72em;
                font-size: 1em
            }
        }

        /* 任意：ダークモードで自動差し替え（flat→neonへ） */
        @media (prefers-color-scheme: dark) {
            .rank-badge.-flat {
                background: #00e5ff;
                color: #04161b;
                border-color: rgba(200, 255, 255, .8);
                box-shadow: 0 0 14px rgba(0, 229, 255, .35);
            }
        }


        @keyframes popIn {
            0% {
                transform: scale(.92);
                opacity: 0
            }

            60% {
                transform: scale(1.04);
                opacity: 1
            }

            100% {
                transform: scale(1)
            }
        }

        .score-head {
            animation: popIn .5s ease-out
        }

        @media (max-width: 640px) {
            .pc-only {
                display: none;
            }

            .mobile-only {
                display: inline;
            }

            .desktop-table {
                display: none;
            }

            .mobile-card-list {
                display: block;
            }

            .history-table {
                display: none;
            }

            .rate-actions .vote-btn {
                margin-left: 0;
            }

            .mobile-history-grid {
                grid-template-columns: 72px minmax(0, 1fr);
            }
        }
    </style>

</head>

<body id="blackBody">
    <div id="topBar">
        <h1 class="score-head">
            結果発表！
            <?php if ($myRow): ?>
                <span class="rank-badge -glass">成績：<?= (int)$myRank ?>位</span>
            <?php endif; ?>
        </h1>
    </div>

    <div id="maincontents">
        <?php if ($__noticeMessage): ?>
            <style>
                .notice-banner {
                    margin: 12px 0;
                    padding: 10px 12px;
                    font-size: 1.8rem;
                    font-weight: 600;
                    line-height: 1.6;
                    color: #fc693cff;
                }

                .notice-banner .fa,
                .notice-banner .fas,
                .notice-banner .far {
                    margin-right: 8px;
                }
            </style>
            <div class="notice-banner">
                <i class="fas fa-flag-checkered" aria-hidden="true"></i>
                <?= htmlspecialchars($__noticeMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        <!-- 順位 -->
        <div class="card">
            <h2>順位</h2>
            <table class="desktop-table">
                <colgroup>
                    <col style="width: 44px;">
                    <col style="width: 9%;">
                    <col style="width: 39%;">
                    <col style="width: 18%;">
                    <col style="width: 24%;">
                </colgroup>
                <thead>
                    <tr>
                        <th class="rank-mark-col" aria-hidden="true"></th>
                        <th>順位</th>
                        <th>名前</th>
                        <th><span class="pc-only">正解数</span><span class="mobile-only">正解</span></th>
                        <th>平均時間</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rank)): ?>
                        <tr>
                            <td colspan="5" class="muted">まだ解答がありません</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rank as $i => $row): ?>
                            <tr class="<?= $row['uid'] === $selfUid ? 'uid' : '' ?>"> <!-- ← $uid → $selfUid -->
                                <td class="rank-mark-cell">
                                    <?php if ($i === 0): ?>
                                        <img class="rank-crown" src="images/icon/king.png" alt="1位">
                                    <?php endif; ?>
                                </td>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int)$row['correct'] ?></td>
                                <td><?= htmlspecialchars($row['avg_elapsed_label'], ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="mobile-card-list">
                <?php if (empty($rank)): ?>
                    <div class="muted">まだ解答がありません</div>
                <?php else: ?>
                    <?php foreach ($rank as $i => $row): ?>
                        <div class="mobile-result-card<?= $row['uid'] === $selfUid ? ' uid' : '' ?>">
                            <div class="mobile-rank-head">
                                <div class="mobile-rank-title">
                                    <?php if ($i === 0): ?>
                                        <img class="rank-crown" src="images/icon/king.png" alt="1位">
                                    <?php endif; ?>
                                    <span><?= $i + 1 ?>位 <?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>
                            <div class="mobile-rank-stats">
                                <div class="mobile-stat">
                                    <span class="mobile-stat-label">正解数</span>
                                    <span class="mobile-stat-value"><?= (int)$row['correct'] ?></span>
                                </div>
                                <div class="mobile-stat">
                                    <span class="mobile-stat-label">平均時間</span>
                                    <span class="mobile-stat-value"><?= htmlspecialchars($row['avg_elapsed_label'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($__noticeMessage === null): ?>
            <div style="text-align:center; margin-bottom: 28px;">
                <a class="qb-round-yellow-btn" href="rematch.php?gid=<?= (int)$gid ?>&prev=<?= (int)$bid ?>">
                    もう1回！
                </a>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>履歴</h2>
            <table class="history-table">
                <colgroup>
                    <col style="width: 8%;">
                    <col style="width: 40%;">
                    <col style="width: 22%;">
                    <col style="width: 12%;">
                    <col style="width: 18%;">
                </colgroup>
                <thead>
                    <tr>
                        <th>Q</th>
                        <th>問題</th>
                        <th>選択肢</th>
                        <th>判定</th>
                        <th>評価</th>
                    </tr>
                </thead>
                <tbody id="rateList">
                <?php if (empty($selfResults)): ?>
                    <tr>
                        <td colspan="5" class="muted">履歴データがありません</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($selfResults as $result): ?>
                        <tr class="history-row"
                            data-cate1="<?= (int)$result['cate1'] ?>"
                            data-cate2="<?= (int)$result['cate2'] ?>"
                            data-id="<?= (int)$result['id'] ?>"
                            data-num="<?= (int)$result['num'] ?>">
                            <td>Q<?= (int)$result['bnum'] ?></td>
                            <td style="text-align:left;"><?= htmlspecialchars($result['mondai'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="text-align:left;">
                                <?php if ($result['answer_label'] !== ''): ?>
                                    <span class="pill"><?= htmlspecialchars($result['answer_label'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                                <?= htmlspecialchars($result['answer_text'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="<?= htmlspecialchars($result['judge_class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($result['judge'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <div class="rate-actions">
                                    <button class="vote-btn vote-good" type="button" aria-label="Good">
                                        <i class="fas fa-thumbs-up"></i> 良い
                                    </button>
                                    <button class="vote-btn vote-bad" type="button" aria-label="Bad">
                                        <i class="fas fa-thumbs-down"></i> 悪い
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <div class="mobile-card-list" data-rate-list>
                <?php if (empty($selfResults)): ?>
                    <div class="muted">履歴データがありません</div>
                <?php else: ?>
                    <?php foreach ($selfResults as $result): ?>
                        <div class="mobile-history-card <?= htmlspecialchars($result['judge_class'], ENT_QUOTES, 'UTF-8') ?>"
                            data-cate1="<?= (int)$result['cate1'] ?>"
                            data-cate2="<?= (int)$result['cate2'] ?>"
                            data-id="<?= (int)$result['id'] ?>"
                            data-num="<?= (int)$result['num'] ?>">
                            <div class="mobile-history-head">Q<?= (int)$result['bnum'] ?></div>
                            <div class="mobile-history-question"><?= htmlspecialchars($result['mondai'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="mobile-history-grid">
                                <div class="mobile-history-label">選択肢</div>
                                <div class="mobile-history-value">
                                    <?php if ($result['answer_label'] !== ''): ?>
                                        <span class="pill"><?= htmlspecialchars($result['answer_label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($result['answer_text'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="mobile-history-label">判定</div>
                                <div class="mobile-history-value <?= htmlspecialchars($result['judge_class'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($result['judge'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="mobile-history-label">評価</div>
                                <div class="mobile-history-value">
                                    <div class="rate-actions">
                                        <button class="vote-btn vote-good" type="button" aria-label="Good">
                                            <i class="fas fa-thumbs-up"></i> 良い
                                        </button>
                                        <button class="vote-btn vote-bad" type="button" aria-label="Bad">
                                            <i class="fas fa-thumbs-down"></i> 悪い
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <p class="rate-note">
                ※ 評価を押して頂けると問題の調査を行います。
            </p>
        </div>
    </div>

    <script>
        /* ★ 追加: Good/Bad 投票 */
        (function() {
            document.addEventListener('click', async (ev) => {
                const btn = ev.target.closest('.vote-btn');
                if (!btn) return;

                const row = ev.target.closest('.history-row, .mobile-history-card');
                if (!row) return;

                const action = btn.classList.contains('vote-good') ? 'good' : 'bad';
                const cate1 = parseInt(row.dataset.cate1, 10);
                const cate2 = parseInt(row.dataset.cate2, 10);
                const id = parseInt(row.dataset.id, 10);
                const num = parseInt(row.dataset.num, 10);

                // 二度押し防止：同一行のボタンを暫定で無効化
                const allBtns = row.querySelectorAll('.vote-btn');
                allBtns.forEach(b => b.disabled = true);

                try {
                    const res = await fetch('api/minaosi_vote.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            cate1,
                            cate2,
                            id,
                            num,
                            action
                        })
                    });

                    const js = await res.json().catch(() => ({}));
                    if (!res.ok || !js.ok) {
                        throw new Error(js.error || '投票に失敗しました');
                    }

                    // 成功UI：押した側を強調、反対側は薄く
                    btn.textContent = (action === 'good') ? '記録：いいね' : '記録：よくない';
                } catch (e) {
                    alert('送信に失敗しました。回線状況をご確認ください。');
                    // 失敗時は再度押せるように戻す
                    allBtns.forEach(b => b.disabled = false);
                }
            });
        })();
    </script>

</body>

</html>
