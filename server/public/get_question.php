<?php
require_once __DIR__ . '/api_bootstrap.php';  // ★ 追加：一番最初に

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/logs/logger.php';
require_once __DIR__ . '/common/define.php';
require_once __DIR__ . '/common/battle_common.php'; // dbConnectPDO() は上のconfigで繋がる前提

$log = Logger::getInstance();

try {

    $pdo = dbConnectPDO();
    if (!$pdo) {
        api_json_fail('DB接続失敗', 500);
    }

    $bid = (int)($_GET['bid'] ?? $_GET['b'] ?? ($_SESSION['bid'] ?? 0));
    $gid = (int)($_GET['gid'] ?? ($_SESSION['gid'] ?? 0));
    if ($bid <= 0 || $gid <= 0) {
        $log->debug("invalid parameters: bid=$bid, gid=$gid");
        api_json_fail('invalid parameters', 400, ['bid' => $bid, 'gid' => $gid]);
    }

    // state取得
    $st = $pdo->prepare("SELECT bnum, q_start_at, reveal_at, switch_at FROM qb_battle_state WHERE bid=? AND gid=? LIMIT 1");
    $st->execute([$bid, $gid]);
    $state = $st->fetch(PDO::FETCH_ASSOC);

    if (!$state || (int)$state['bnum'] <= 0) {
        api_json_fail('not ready / no current question');
    }
    $bnum = (int)$state['bnum'];
    $nowMs = (int)floor(microtime(true) * 1000);
    $revealPendingMs = defined('BATTLE_REVEAL_PENDING_MS') ? BATTLE_REVEAL_PENDING_MS : 1000;
    $answerCloseAt = max((int)$state['q_start_at'], (int)$state['reveal_at'] - $revealPendingMs);
    if ($nowMs < (int)$state['q_start_at']) {
        api_json_fail('not ready / countdown', 200, [
            'phase_hint' => 'countdown',
            'bnum' => $bnum,
            'now_ms' => $nowMs,
            'q_start_at' => (int)$state['q_start_at'],
            'reveal_at' => (int)$state['reveal_at'],
            'switch_at' => (int)$state['switch_at'],
        ]);
    }
    $showAnswer = ($nowMs >= (int)$state['reveal_at']) ? 1 : 0;
    $revealPending = (!$showAnswer && $nowMs >= $answerCloseAt) ? 1 : 0;

    // lineup → 問題キー
    // lineup 取得
    $lk = $pdo->prepare("SELECT cate1,cate2,id,num,display FROM qb_battle_lineup WHERE bid=? AND order_no=? LIMIT 1");
    $lk->execute([$bid, $bnum]);
    $key = $lk->fetch(PDO::FETCH_ASSOC);

    $log->debug('[GETQ] lineupRow=' . json_encode($key, JSON_UNESCAPED_UNICODE)); // ★追加

    if (!$key) {
        // ★ログを出す（原因特定が速くなる）
        $mx = $pdo->prepare("SELECT COUNT(*) c, MIN(order_no) mi, MAX(order_no) mx FROM qb_battle_lineup WHERE bid=?");
        $mx->execute([$bid]);
        $a = $mx->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'mi' => null, 'mx' => null];
        $log->debug(sprintf(
            "[QUESTION][LINEUP_MISSING] bid=%d gid=%d bnum=%d lineup_count=%d range=%s..%s",
            $bid,
            $gid,
            $bnum,
            (int)$a['c'],
            $a['mi'],
            $a['mx']
        ));
        api_json_fail('lineup not found', 404);
    }

    // 回答数・参加者数
    $answeredPlayers = 0;
    $totalPlayers    = 0;
    $allAnswered     = 0;

    try {
        $stAns = $pdo->prepare("
            SELECT COUNT(DISTINCT uid)
              FROM qb_buzzes
             WHERE bid=? AND gid=? AND cate1=? AND cate2=? AND id=? AND num=?
        ");
        $stAns->execute([$bid, $gid, (int)$key['cate1'], (int)$key['cate2'], (int)$key['id'], (int)$key['num']]);
        $answeredPlayers = (int)$stAns->fetchColumn();
    } catch (\Throwable $e) {
        $log->debug('[GETQ] count answers failed: ' . $e->getMessage());
    }

    try {
        $stTotal = $pdo->prepare("
            SELECT COUNT(DISTINCT uid)
              FROM qb_battle_participants
             WHERE bid=? AND gid=? AND last_ping > NOW() - INTERVAL 60 SECOND
        ");
        $stTotal->execute([$bid, $gid]);
        $totalPlayers = (int)$stTotal->fetchColumn();
    } catch (\Throwable $e) {
        $log->debug('[GETQ] count participants failed: ' . $e->getMessage());
    }

    if ($totalPlayers > 0 && $answeredPlayers >= $totalPlayers) {
        $allAnswered = 1;
    }

    // ★UIヒント用（現行のタイマー制御から推定した簡易フェーズ）
    $phase_hint = ($nowMs < (int)$state['q_start_at']) ? 'countdown' : ($showAnswer ? 'reveal' : ($revealPending ? 'reveal_pending' : 'answering'));

    // ★任意: ラスト問題か？（次のボタン表示・結果遷移の判断に便利）
    $isLast = 0;
    try {
        $stQ = $pdo->prepare("SELECT COUNT(*) FROM qb_battle_lineup WHERE bid=?");
        $stQ->execute([$bid]);
        $totalQ = (int)$stQ->fetchColumn();
        if ($totalQ > 0 && $bnum >= $totalQ) $isLast = 1;
    } catch (\Throwable $e) {
        $log->debug('[GETQ] totalQ failed: ' . $e->getMessage());
    }

    // 本文
    $mq = $pdo->prepare(
        "SELECT mondai, qa, qb, qc, qd, kaito
     FROM qb_question_bank
    WHERE cate1=? AND cate2=? AND qid=? AND qnum=? AND del=0
    LIMIT 1"
    );
    $mq->execute([(int)$key['cate1'], (int)$key['cate2'], (int)$key['id'], (int)$key['num']]);
    $md = $mq->fetch(PDO::FETCH_ASSOC);

    if (!$md) {
        api_json_fail('question not found', 404);
    }

    $themeTitle = '';
    try {
        $stTitle = $pdo->prepare("
            SELECT title
              FROM qb_question_category
             WHERE cate1 = ? AND cate2 = ? AND qid = ?
             LIMIT 1
        ");
        $stTitle->execute([(int)$key['cate1'], (int)$key['cate2'], (int)$key['id']]);
        $themeTitle = (string)($stTitle->fetchColumn() ?: '');
    } catch (\Throwable $e) {
        $log->debug('[GETQ] themeTitle fetch failed: ' . $e->getMessage());
    }

    // 表示用見出し（任意。不要なら空でOK）
    $display = (string)($key['display'] ?? ''); // 例: "ジャンル＞タイトルの問題" などにしたい場合はJOINで作る
    $log->debug('[GETQ] displayRaw=' . json_encode($display, JSON_UNESCAPED_UNICODE)); // ★追加

    // ★保険：displayが空だった場合はカテゴリキャッシュから合成
    if ($display === '') {
        try {
            $st2 = $pdo->prepare("
            SELECT id, title
              FROM (
                    SELECT 0 AS id, title
                      FROM qb_question_category
                     WHERE cate1 = ? AND cate2 = 0 AND qid = 0
                    UNION ALL
                    SELECT qid AS id, title
                      FROM qb_question_category
                     WHERE cate1 = ? AND cate2 = ? AND qid = ?
              ) t
        ");
            $st2->execute([(int)$key['cate1'], (int)$key['cate1'], (int)$key['cate2'], (int)$key['id']]);
            $genre = '';
            $title = '';
            while ($r = $st2->fetch(PDO::FETCH_ASSOC)) {
                if ((int)$r['id'] === 0) $genre = (string)$r['title'];
                if ((int)$r['id'] === (int)$key['id']) $title = (string)$r['title'];
            }
            $display = ($genre !== '' || $title !== '') ? ($genre . '＞' . $title) : '';
            if ($themeTitle === '' && $title !== '') {
                $themeTitle = $title;
            }
            $log->debug('[GETQ] displayFallback=' . json_encode($display, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $log->debug('[GETQ] displayFallback error: ' . $e->getMessage());
        }
    }

    if ($themeTitle === '' && $display !== '') {
        $themeTitle = preg_replace('/^.*＞/u', '', $display);
        $themeTitle = preg_replace('/^「/u', '', (string)$themeTitle);
        $themeTitle = preg_replace('/」の問題$/u', '', (string)$themeTitle);
        $themeTitle = trim((string)$themeTitle);
    }

    // 成功レスポンス
    api_json_send([
        'error'       => 0,
        'bid'         => $bid,
        'gid'         => $gid,
        'bnum'        => $bnum,
        'ts'          => (int)$state['q_start_at'],   // 出題トークンとして開始時刻を流用
        'cate1'       => (int)$key['cate1'],
        'cate2'       => (int)$key['cate2'],
        'id'          => (int)$key['id'],
        'num'         => (int)$key['num'],

        'display'     => $display,
        'theme_title' => $themeTitle,
        'mondai'      => (string)$md['mondai'],
        'qa'          => (string)$md['qa'],
        'qb'          => (string)$md['qb'],
        'qc'          => (string)$md['qc'],
        'qd'          => (string)$md['qd'],
        'kaito'       => $showAnswer ? strtoupper((string)$md['kaito']) : null,
        'show_answer' => $showAnswer,
        'answer_close_at' => $answerCloseAt,
        'reveal_pending'  => $revealPending ? 1 : 0,
        'answered_players' => $answeredPlayers,
        'total_players'    => $totalPlayers,
        'all_answered'     => $allAnswered,     // 全員回答済みなら 1

        'phase_hint'       => $phase_hint,      // 'answering' | 'reveal' | 'countdown'
        'now_ms'           => $nowMs,           // クライアント側の進行バー等で利用可
        'reveal_at'        => (int)$state['reveal_at'],
        'switch_at'        => (int)$state['switch_at'],

        'is_last'          => $isLast           // 任意: 最終問なら 1
    ]);
} catch (Throwable $e) {
    // 例外はログに残してから、JSON 500
    $log->debug('get_question exception: ' . $e->getMessage());
    api_json_fail('internal error', 500);
}
