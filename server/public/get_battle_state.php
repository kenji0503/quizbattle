<?php
require_once __DIR__ . '/api_bootstrap.php';  // ★ 追加：一番最初に
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/logs/logger.php';
require_once __DIR__ . '/common/define.php';
require_once __DIR__ . '/common/battle_common.php'; // dbConnectPDO() は上のconfigで繋がる前提

$log = Logger::getInstance();
$log->debug('** get_battle_state.php start');

try {

    $pdo = dbConnectPDO();
    if (!$pdo) {
        api_json_fail('DB接続失敗', 500);
    }

    // ---- 設定（必要に応じて調整）----
    if (!defined('QB_QUESTION_MS')) define('QB_QUESTION_MS', 8000); // 出題時間
    if (!defined('QB_ANSWER_MS'))   define('QB_ANSWER_MS',   8000); // 正解表示時間
    if (!defined('BATTLE_COUNTDOWN_MS')) define('BATTLE_COUNTDOWN_MS', 1000);
    if (!defined('BATTLE_REVEAL_PENDING_MS')) define('BATTLE_REVEAL_PENDING_MS', 1000);

    $bid = (int)($_GET['bid'] ?? 0);
    $gid = (int)($_GET['gid'] ?? 0);
    if ($bid <= 0 || $gid <= 0) {
        $log->debug("invalid parameters: bid=$bid, gid=$gid");
        api_json_fail('invalid parameters', 400, ['bid' => $bid, 'gid' => $gid]);
    }
    $nowMs = (int)floor(microtime(true) * 1000);

    // 現在の state を取得
    $sql = "SELECT bid,gid,bnum,phase,q_start_at,reveal_at,switch_at,ts_ms
          FROM qb_battle_state
         WHERE bid=? AND gid=?
         ORDER BY ts_ms DESC
         LIMIT 1";

    $st  = $pdo->prepare($sql);
    $st->execute([$bid, $gid]);
    $state = $st->fetch(PDO::FETCH_ASSOC);

    if (!$state) {
        api_json_fail('state not found');
    }

    // 導出
    [$phaseDerived, $showAnswer, $nextAt] = derivePhaseAndNextAt($state, $nowMs);

    // 取得直後スナップショット
    $phaseStored = (int)$state['phase']; // DBにあるphase（参考値）
    $log->debug(sprintf(
        '[STATE_BEFORE] bid=%d gid=%d now=%s bnum=%d phase_db=%d phase_derived=%d q=%s rv=%s sw=%s next=%s',
        (int)$state['bid'],
        (int)$state['gid'],
        fmt_ms($nowMs),
        (int)$state['bnum'],
        $phaseStored,
        (int)$phaseDerived,
        fmt_ms((int)$state['q_start_at']),
        fmt_ms((int)$state['reveal_at']),
        fmt_ms((int)$state['switch_at']),
        fmt_ms((int)$nextAt)
    ));

    // ラインナップ最大 order_no（終了理由の算出とログに使う）
    $stMax = $pdo->prepare("SELECT MAX(order_no) FROM qb_battle_lineup WHERE bid=?");
    $stMax->execute([$bid]);
    $maxNo = (int)($stMax->fetchColumn() ?? 0);

    // 終了候補の“理由”を先に決めておく（常にレスポンスへ返すため）
    $reason = 'running';
    if ($maxNo > 0 && (int)$state['bnum'] > $maxNo) {
        $reason = 'finished_by_bnum';
    } elseif ($nowMs >= (int)$state['switch_at']) {
        $reason = 'finished_by_time';
    }

    // （任意）時刻の並びが壊れていたら警告ログ
    if (!((int)$state['q_start_at'] <= (int)$state['reveal_at'] && (int)$state['reveal_at'] <= (int)$state['switch_at'])) {
        $log->warning(sprintf(
            '[TIME_ORDER_BROKEN] bid=%d gid=%d q=%s rv=%s sw=%s',
            $bid,
            $gid,
            fmt_ms((int)$state['q_start_at']),
            fmt_ms((int)$state['reveal_at']),
            fmt_ms((int)$state['switch_at'])
        ));
    }

    // ---- 自動繰り上げ（switch_at 経過時 & まだ確定FINISHEDでない時だけ）----
    if ($nowMs >= (int)$state['switch_at'] && (int)$state['phase'] !== 3) {
        // lineup の最大問番号（bid単位）
        $stMax = $pdo->prepare("SELECT MAX(order_no) FROM qb_battle_lineup WHERE bid=?");
        $stMax->execute([$bid]);
        $maxNo = (int)($stMax->fetchColumn() ?? 0);

        $currBnum = (int)$state['bnum'];
        $nextBnum = $currBnum + 1;

        $newStart = $newReveal = $newSwitch = null;

        if ($maxNo >= 1 && $nextBnum <= $maxNo) {
            // 次問開始前にも全員向けの待機カウントダウンを入れる
            $newStart  = $nowMs + BATTLE_COUNTDOWN_MS;
            $newReveal = $newStart + QB_QUESTION_MS + BATTLE_REVEAL_PENDING_MS;
            $newSwitch = $newReveal + QB_ANSWER_MS;

            $up = $pdo->prepare(
                "UPDATE qb_battle_state
               SET bnum=?, phase=0,
                   q_start_at=?, reveal_at=?, switch_at=?,
                   ts_ms=?
             WHERE bid=? AND gid=? AND bnum=?"
            );
            $up->execute([$nextBnum, $newStart, $newReveal, $newSwitch, $nowMs, $bid, $gid, $currBnum]);
            battle_update_lineup_schedule($pdo, $bid, $nextBnum, $newStart, $newReveal, $newSwitch);
        } else {
            // 最終問題終了 → FINISHED
            $up = $pdo->prepare("UPDATE qb_battle_state SET phase=3, ts_ms=? WHERE bid=? AND gid=?");
            $up->execute([$nowMs, $bid, $gid]);
            battle_update_lineup_schedule($pdo, $bid, $currBnum, (int)$state['q_start_at'], (int)$state['reveal_at'], (int)$state['switch_at']);
        }

        // 取り直し & 再導出
        $st->execute([$bid, $gid]);
        $state = $st->fetch(PDO::FETCH_ASSOC);
        [$phaseDerived, $showAnswer, $nextAt] = derivePhaseAndNextAt($state, $nowMs);

        // 自動繰り上げ後のスナップショット
        $log->debug(sprintf(
            '[STATE_AFTER] bid=%d gid=%d now=%s bnum=%d phase=%d q=%s rv=%s sw=%s next=%s',
            (int)$state['bid'],
            (int)$state['gid'],
            fmt_ms($nowMs),
            (int)$state['bnum'],
            (int)$phaseDerived,
            fmt_ms((int)$state['q_start_at']),
            fmt_ms((int)$state['reveal_at']),
            fmt_ms((int)$state['switch_at']),
            fmt_ms(
                (int)$nextAt,
                $reason,
                $maxNo
            )
        ));

        // 分岐別ログ（未定義参照を防止）
        if ($newStart !== null) {
            $log->debug(sprintf(
                '[ADVANCE_SET] bid=%d gid=%d bnum:%d->%d q=%s rv=%s sw=%s',
                $bid,
                $gid,
                (int)$currBnum,
                (int)$nextBnum,
                fmt_ms((int)$newStart),
                fmt_ms((int)$newReveal),
                fmt_ms((int)$newSwitch)
            ));
        } else {
            $log->debug(sprintf(
                '[FINISHED_SET] bid=%d gid=%d bnum=%d phase=%d',
                $bid,
                $gid,
                (int)$state['bnum'],
                (int)$phaseDerived
            ));
        }
    }

    // 返却直前あたりに追加

    function phase_hint_of(int $p): string
    {
        if ($p <= 0) return 'waiting';
        if ($p === 1) return 'answering';
        if ($p === 2) return 'reveal';
        return 'finished';
    }

    $isLast = ($maxNo > 0 && (int)$state['bnum'] >= $maxNo) ? 1 : 0;
    $allAnswered = 0;
    $answerCloseAt = max((int)$state['q_start_at'], (int)$state['reveal_at'] - BATTLE_REVEAL_PENDING_MS);
    $revealPending = ((int)$phaseDerived === 1 && $nowMs >= $answerCloseAt) ? 1 : 0;

    try {
        $lk = $pdo->prepare("
            SELECT cate1, cate2, id, num
              FROM qb_battle_lineup
             WHERE bid = ? AND order_no = ?
             LIMIT 1
        ");
        $lk->execute([$bid, (int)$state['bnum']]);
        $key = $lk->fetch(PDO::FETCH_ASSOC);

        if ($key) {
            $stAns = $pdo->prepare("
                SELECT COUNT(DISTINCT uid)
                  FROM qb_buzzes
                 WHERE bid = ? AND gid = ? AND cate1 = ? AND cate2 = ? AND id = ? AND num = ?
            ");
            $stAns->execute([$bid, $gid, (int)$key['cate1'], (int)$key['cate2'], (int)$key['id'], (int)$key['num']]);
            $answered = (int)$stAns->fetchColumn();

            $stPlayers = $pdo->prepare("
                SELECT COUNT(DISTINCT uid)
                  FROM qb_battle_participants
                 WHERE bid = ? AND gid = ? AND last_ping > NOW() - INTERVAL 60 SECOND
            ");
            $stPlayers->execute([$bid, $gid]);
            $players = (int)$stPlayers->fetchColumn();

            if ($players > 0 && $answered >= $players) {
                $allAnswered = 1;
            }
        }
    } catch (\Throwable $e) {
        $log->debug('get_battle_state all_answered failed: ' . $e->getMessage());
    }

    api_json_send([
        'bid'         => (int)$state['bid'],
        'gid'         => (int)$state['gid'],
        'bnum'        => (int)$state['bnum'],
        'phase'       => (int)$phaseDerived,
        'phase_hint'  => phase_hint_of((int)$phaseDerived),   // ← 追加
        'is_last'     => $isLast,                              // ← 追加
        'all_answered' => $allAnswered,
        'answer_close_at' => $answerCloseAt,
        'reveal_pending' => $revealPending,
        'ts'          => (int)$state['ts_ms'],
        'show_answer' => $showAnswer ? 1 : 0,
        'next_at'     => (int)$nextAt,
        'now'         => $nowMs,
        'q_start_at'  => (int)$state['q_start_at'],
        'reveal_at'   => (int)$state['reveal_at'],
        'switch_at'   => (int)$state['switch_at'],
        'reason'      => $reason,
        'max_order'   => $maxNo
    ]);
} catch (Throwable $e) {
    // 例外はログに残してから、JSON 500
    $log->debug('get_battle_state exception: ' . $e->getMessage());
    api_json_fail('internal error', 500);
}

// ===== ヘルパ =====
function derivePhaseAndNextAt(array $state, int $nowMs): array
{
    $q  = (int)$state['q_start_at'];
    $rv = (int)$state['reveal_at'];
    $sw = (int)$state['switch_at'];
    if ($nowMs < $q)      return [0, 0, $q];
    if ($nowMs < $rv)     return [1, 0, $rv];
    if ($nowMs < $sw)     return [2, 1, $sw];
    return [3, 1, $sw];
}

// 可読フォーマット（例: 13:05:42.123）
if (!function_exists('fmt_ms')) {
    function fmt_ms(int $ms): string
    {
        $s   = (int) floor($ms / 1000);
        $rem = $ms % 1000;
        return date('H:i:s', $s) . '.' . str_pad((string)$rem, 3, '0', STR_PAD_LEFT);
    }
}
