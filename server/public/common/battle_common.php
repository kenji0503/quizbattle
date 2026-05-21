<?php

// ---- helper: ms(Unix epoch) -> "HH:MM:SS.mmm" （サーバのデフォルトTZで表示）
if (!function_exists('fmt_ms')) {
    function fmt_ms(int $ms): string
    {
        $sec = intdiv($ms, 1000);
        $mss = $ms % 1000;
        return date('H:i:s', $sec) . sprintf('.%03d', $mss);
    }
}

function dbConnectPDO()
{
    $dsn  = defined('DB_DSN') ? DB_DSN : (getenv('QB_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=battle_test;charset=utf8mb4');
    $user = defined('DB_USER') ? DB_USER : (getenv('QB_DB_USER') ?: 'root');
    $pass = defined('DB_PASS') ? DB_PASS : (getenv('QB_DB_PASS') ?: '');

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,      // 例外モード
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,            // fetch_assoc 相当
        PDO::ATTR_EMULATE_PREPARES   => false,                       // 生のプリペアドステートメント
    ];
    return new PDO($dsn, $user, $pass, $options);
}

function linkUnit($anker, $icon, $label)
{
    echo '<a href="' . $anker . '" style="text-decoration: none">';
    echo '<div class="nav-image">';
    echo '<div class="restart_icon nav-image">';
    echo '<span class="' . $icon . '"></span>';
    echo '<span class="icon-text">' . $label . '</span>';
    echo '</div> ';
    echo '</div>';
    echo '<span class="nav-span">' . $label . '</span>';
    echo '</a>';
}

function getTitle($cate1, $cate2, $id)
{
    $pdo = dbConnectPDO();

    // タイトル名取得
    $sql = "SELECT title FROM qb_question_category WHERE cate1 = :c1 AND cate2 = :c2 AND qid = :id LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':c1' => $cate1,
        ':c2' => $cate2,
        ':id' => $id
    ]);
    $title = $st->fetchColumn();

    // ジャンル名（id=0固定）
    $sql2 = "SELECT title FROM qb_question_category WHERE cate1 = :c1 AND cate2 = 0 AND qid = 0 LIMIT 1";
    $st2 = $pdo->prepare($sql2);
    $st2->execute([
        ':c1' => $cate1
    ]);
    $genre = $st2->fetchColumn();

    return [
        'genre' => $genre ?: '',
        'title' => $title ?: ''
    ];
}


function getGroupName($gid)
{
    $pdo = dbConnectPDO();
    $sql = "SELECT name FROM qb_group WHERE gid = :gid";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':gid', $gid, PDO::PARAM_INT);
    $stmt->execute();
    $group = $stmt->fetch();

    return $group ? htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8') : '不明なグループ';
}

function getUserName($gid, $uid)
{
    $pdo = dbConnectPDO();
    $sql = "
        SELECT name
          FROM qb_battle_participants
         WHERE gid = :gid AND uid = :uid
         ORDER BY last_ping DESC, joined_at DESC
         LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':gid', $gid, PDO::PARAM_INT);
    $stmt->bindValue(':uid', $uid, PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch();

    return $user ? htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') : '不明なユーザー';
}

function abs_url(string $path): string
{
    if (defined('BASE_URL') && BASE_URL !== '') {
        return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    return $scheme . '://' . $host . $base . '/' . ltrim($path, '/');
}

function battle_ws_public_url(): string
{
    $explicit = envValueAny(['QB_WS_PUBLIC_URL', 'WS_PUBLIC_URL'], '');
    if ($explicit !== '') {
        return $explicit;
    }

    $baseUrl = defined('BASE_URL') ? (string)BASE_URL : '';
    if ($baseUrl !== '') {
        $parts = parse_url($baseUrl);
        $scheme = (($parts['scheme'] ?? 'https') === 'https') ? 'wss' : 'ws';
        $host = $parts['host'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $scheme . '://' . $host . $port . '/ws';
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'wss' : 'ws';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/ws';
}

function battle_ws_internal_url(): string
{
    $explicit = envValueAny(['QB_WS_INTERNAL_URL', 'WS_INTERNAL_URL'], '');
    if ($explicit !== '') {
        return $explicit;
    }

    $port = envValueAny(['QB_WS_PORT', 'WS_PORT'], '8081') ?? '8081';
    $port = preg_replace('/[^0-9]/', '', (string)$port);
    if ($port === '') {
        $port = '8081';
    }

    return 'http://127.0.0.1:' . $port . '/publish';
}

function battle_ws_secret(): string
{
    return envValueAny(['QB_WS_SECRET', 'WS_SECRET'], '') ?? '';
}

function battle_has_lineup_schedule_columns(PDO $pdo): bool
{
    static $cache = [];

    $key = spl_object_id($pdo);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $st = $pdo->query("
        SELECT COUNT(*)
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'qb_battle_lineup'
           AND COLUMN_NAME IN ('q_start_at_ms', 'reveal_at_ms', 'switch_at_ms')
    ");
    $count = (int)($st ? $st->fetchColumn() : 0);
    $cache[$key] = ($count === 3);
    return $cache[$key];
}

function battle_update_lineup_schedule(PDO $pdo, int $bid, int $orderNo, int $qStartAtMs, int $revealAtMs, int $switchAtMs): void
{
    if (!battle_has_lineup_schedule_columns($pdo)) {
        return;
    }

    $st = $pdo->prepare("
        UPDATE qb_battle_lineup
           SET q_start_at_ms = :q_start_at_ms,
               reveal_at_ms = :reveal_at_ms,
               switch_at_ms = :switch_at_ms
         WHERE bid = :bid
           AND order_no = :order_no
    ");
    $st->execute([
        ':q_start_at_ms' => $qStartAtMs,
        ':reveal_at_ms' => $revealAtMs,
        ':switch_at_ms' => $switchAtMs,
        ':bid' => $bid,
        ':order_no' => $orderNo,
    ]);
}

function battle_derive_phase(int $nowMs, int $qStartAt, int $revealAt, int $switchAt): int
{
    if ($nowMs < $qStartAt) return BATTLE_PHASE_WAIT;
    if ($nowMs < $revealAt) return BATTLE_PHASE_QUESTION;
    if ($nowMs < $switchAt) return BATTLE_PHASE_ANSWER;
    return BATTLE_PHASE_FINISHED;
}

function battle_collect_lobby_snapshot(PDO $pdo, int $bid, int $gid): array
{
    $st = $pdo->prepare("
        SELECT name, avatar_type
          FROM qb_battle_participants
         WHERE bid = :bid
           AND gid = :gid
           AND last_ping > NOW() - INTERVAL 60 SECOND
         ORDER BY uid ASC
    ");
    $st->execute([':bid' => $bid, ':gid' => $gid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $participants = [];
    $avatars = [];
    foreach ($rows as $row) {
        $name = (string)($row['name'] ?? '');
        if ($name === '') continue;
        $participants[] = $name;
        $avatars[] = [
            'name' => $name,
            'type' => (string)($row['avatar_type'] ?? ''),
        ];
    }

    $ss = $pdo->prepare("
        SELECT bid, gid, bnum, q_start_at, reveal_at, switch_at, ts_ms
          FROM qb_battle_state
         WHERE bid = :bid
         ORDER BY ts_ms DESC
         LIMIT 1
    ");
    $ss->execute([':bid' => $bid]);
    $state = $ss->fetch(PDO::FETCH_ASSOC) ?: null;

    $nowMs = (int)floor(microtime(true) * 1000);
    $phase = 0;
    $started = false;
    $startAt = null;
    $shouldGo = 0;

    if ($state) {
        $phase = battle_derive_phase(
            $nowMs,
            (int)$state['q_start_at'],
            (int)$state['reveal_at'],
            (int)$state['switch_at']
        );
        $started = true;
        if ($phase === BATTLE_PHASE_WAIT) {
            $startAt = (int)$state['q_start_at'];
        }
        if ($phase >= BATTLE_PHASE_QUESTION) {
            $shouldGo = 1;
        }
    }

    return [
        'participants' => $participants,
        'avatars' => $avatars,
        'count' => count($participants),
        'started' => $started,
        'phase' => $phase,
        'start_at' => $startAt,
        'now' => $nowMs,
        'should_go' => $shouldGo,
        'q_start_at' => $state ? (int)$state['q_start_at'] : null,
        'reveal_at' => $state ? (int)$state['reveal_at'] : null,
        'switch_at' => $state ? (int)$state['switch_at'] : null,
        'bid' => $bid,
        'gid' => $gid,
    ];
}

function battle_ws_publish(array $rooms, string $event, array $payload = []): bool
{
    $rooms = array_values(array_filter(array_map('strval', $rooms), static fn($room) => $room !== ''));
    if ($rooms === []) {
        return false;
    }

    $body = json_encode([
        'event' => $event,
        'rooms' => $rooms,
        'payload' => $payload,
        'secret' => battle_ws_secret(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($body === false) {
        return false;
    }

    $headers = [
        'Content-Type: application/json; charset=UTF-8',
        'Connection: close',
    ];

    $secret = battle_ws_secret();
    if ($secret !== '') {
        $headers[] = 'X-QB-WS-Secret: ' . $secret;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 0.5,
            'ignore_errors' => true,
        ],
    ]);

    try {
        $result = @file_get_contents(battle_ws_internal_url(), false, $context);
        return $result !== false;
    } catch (\Throwable $e) {
        return false;
    }
}


/**
 * sc_groupテーブルからグループ情報を取得する
 *
 * @param int $groupId グループID
 * @return array|null グループ情報（連想配列）、存在しない場合はnull
 */
function getGroupById($groupId)
{
    $pdo = dbConnectPDO();
    if (!$pdo) {
        throw new RuntimeException('DB接続に失敗しました');
    }

    // manager_email と description の間にカンマが抜けていたため正しく取得できないバグを修正
    $sql = 'SELECT gid, name, manager_name, manager_email FROM sc_group WHERE gid = :gid';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':gid', $groupId, PDO::PARAM_INT);
    $stmt->execute();

    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    return $group ?: null;
}

/**
 * sc_groupテーブルから全グループ情報を取得する
 *
 * @return array グループ情報の配列（各要素は連想配列）
 */
function getAllGroups()
{
    $pdo = dbConnectPDO();
    if (!$pdo) {
        throw new RuntimeException('DB接続に失敗しました');
    }

    $sql = 'SELECT gid, name, manager_name, manager_email FROM sc_group ORDER BY gid ASC';
    $stmt = $pdo->query($sql);

    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $groups;
}

// 既存の require / 関数群 … の下あたりに追記

/**
 * 即席グループ配下を丸ごと削除する（安全のため is_temp=1 のグループのみ）
 * - sc_current_question（該当gid）
 * - sc_buzzes（該当gid）
 * - sc_seiseki（該当gidのユーザに紐づく全成績）
 * - sc_taikai（該当gid & is_temp=1）
 * - sc_user（該当gid & role=TEMP）
 * - sc_group（該当gid & is_temp=1）
 */
function haya_delete_temp_group_all(PDO $pdo, int $gid): void
{
    $pdo->beginTransaction();
    try {
        // tempグループか確認
        $stmt = $pdo->prepare("SELECT is_temp FROM sc_group WHERE gid = :gid");
        $stmt->execute([':gid' => $gid]);
        $isTemp = (int)($stmt->fetchColumn() ?? 0);
        if ($isTemp !== 1) {
            throw new RuntimeException("指定gidは即席グループではありません（gid={$gid}）");
        }

        // 1) カレント問題
        $stmt = $pdo->prepare("DELETE FROM sc_current_question WHERE gid = :gid");
        $stmt->execute([':gid' => $gid]);

        // 2) ブザー
        $stmt = $pdo->prepare("DELETE FROM sc_buzzes WHERE gid = :gid");
        $stmt->execute([':gid' => $gid]);

        // 3) 成績（該当グループのユーザに紐づく全成績をJOINで削除）
        $stmt = $pdo->prepare("
            DELETE s
              FROM sc_seiseki s
              JOIN sc_user u ON s.uid = u.uid
             WHERE u.gid = :gid
        ");
        $stmt->execute([':gid' => $gid]);

        // 4) 即席大会（このグループ配下のみ）
        $stmt = $pdo->prepare("DELETE FROM sc_taikai WHERE gid = :gid AND is_temp = 1");
        $stmt->execute([':gid' => $gid]);

        // 5) 即席ユーザ（このグループ配下のみ）
        //    ※ 誤削除防止のため role=TEMP のみを削除
        $tempRole = defined('ROLE_TEMP') ? (int)ROLE_TEMP : 1;
        $stmt = $pdo->prepare("DELETE FROM sc_user WHERE gid = :gid AND role = :role");
        $stmt->execute([':gid' => $gid, ':role' => $tempRole]);

        // 6) グループ本体
        $stmt = $pdo->prepare("DELETE FROM sc_group WHERE gid = :gid AND is_temp = 1");
        $stmt->execute([':gid' => $gid]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * 大会IDから同一の“即席グループ”を特定して一括削除
 * （大会一覧からの削除時に使用）
 */
function haya_delete_temp_taikai_all(PDO $pdo, int $tid): void
{
    $stmt = $pdo->prepare("SELECT gid, is_temp FROM sc_taikai WHERE tid = :tid");
    $stmt->execute([':tid' => $tid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException("大会が見つかりません（tid={$tid}）");
    }
    if ((int)$row['is_temp'] !== 1) {
        throw new RuntimeException("即席大会ではありません（tid={$tid}）");
    }
    haya_delete_temp_group_all($pdo, (int)$row['gid']);
}


/**
 * セッションが有効か検証（多重ログイン防止）
 * 
 * @param PDO $pdo DB接続済のPDOインスタンス
 * @param bool $exitOnFail 検証失敗時に即終了するか（trueならexit）
 * @return bool 検証OKならtrue, NGならfalse
 */
function verify_session(PDO $pdo, bool $exitOnFail = true): bool
{
    $uid = (int)($_SESSION['uid'] ?? 0);
    $gid = (int)($_SESSION['gid'] ?? 0);
    $bid = (int)($_SESSION['bid'] ?? 0);

    // ★デバッグ：開始ログ
    if (class_exists('Logger')) {
        $log = Logger::getInstance();
        $log->debug("verify_session start: uid={$uid}, gid={$gid}, bid={$bid}");
    }

    if ($uid <= 0) {
        if ($exitOnFail) {
            http_response_code(403);
            echo json_encode(['error' => 'ログイン情報が見つかりません']);
            exit;
        }
        return false;
    }

    if ($gid > 0 && $bid > 0) {
        $stmt = $pdo->prepare("
            SELECT 1
              FROM qb_battle_participants
             WHERE uid = ? AND gid = ? AND bid = ?
             LIMIT 1
        ");
        $stmt->execute([$uid, $gid, $bid]);
        $ok = (bool)$stmt->fetchColumn();
        if (!$ok) {
            if ($exitOnFail) {
                http_response_code(403);
                echo json_encode(['error' => '参加セッションが無効です']);
                exit;
            }
            return false;
        }
    }
    return true;
}


/**
 * gid重複排除して整形
 */
function uniq_by_gid(array $rows): array
{
    $seen = [];
    $out  = [];
    foreach ($rows as $r) {
        $gid = (int)($r['gid'] ?? 0);
        if (!$gid || isset($seen[$gid])) continue;
        $seen[$gid] = true;
        $out[] = [
            'gid'  => $gid,
            'name' => $r['name'] ?? getGroupName($gid),
        ];
    }
    usort($out, fn($a, $b) => $a['gid'] <=> $b['gid']);
    return $out;
}

/**
 * 先生/管理者の「選択可能グループ」一覧を返す（UNIONで重複排除）
 * - ADMIN: 全グループ（gid, name, manager_name, manager_email を返す想定）
 * - TEACHER: 自分の所属グループ + 自分が作成した大会に紐づくグループ
 */
function getSelectableGroupsForUser(PDO $pdo, int $role, ?int $login_gid, int $uid): array
{
    $adminRole = defined('ROLE_ADMIN') ? (int)ROLE_ADMIN : 9;
    if ($role === $adminRole) {
        // getAllGroups() は gid, name, manager_name, manager_email を返す実装にしておく
        return getAllGroups();
    }

    // login_gid がある場合は 1行＋EXISTS の結果を UNION（重複は自動で除外）
    if (!empty($login_gid)) {
        $sql = "
            (SELECT g.gid, g.name, g.manager_name, g.manager_email
               FROM sc_group g
              WHERE g.gid = :login_gid)
            UNION
            (SELECT g.gid, g.name, g.manager_name, g.manager_email
               FROM sc_group g
              WHERE EXISTS (
                        SELECT 1
                          FROM sc_taikai t
                         WHERE t.gid = g.gid
                           AND t.make_uid = :uid
                    ))
            ORDER BY gid
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':login_gid', (int)$login_gid, PDO::PARAM_INT);
        $stmt->bindValue(':uid', (int)$uid, PDO::PARAM_INT);
    } else {
        // login_gid が無い場合は EXISTS 側だけ
        $sql = "
            SELECT g.gid, g.name, g.manager_name, g.manager_email
              FROM sc_group g
             WHERE EXISTS (
                    SELECT 1
                      FROM sc_taikai t
                     WHERE t.gid = g.gid
                       AND t.make_uid = :uid
                  )
             ORDER BY g.gid
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':uid', (int)$uid, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ──────────────────────────────────────────────
// Cache wrapper: APCu → ファイル（tmp→renameで原子的書込）
// 使い方:
//   $v = haya_cache_get($key); if ($v !== false) { ... }
//   haya_cache_set($key, $json, 1);   // TTL秒
//   haya_cache_delete($key);
// ──────────────────────────────────────────────

if (!defined('HAYA_CACHE_DIR')) {
    define('HAYA_CACHE_DIR', __DIR__ . '/cache'); // 書込み可能な場所に
}
if (!defined('HAYA_CACHE_TTL_DEFAULT')) define('HAYA_CACHE_TTL_DEFAULT', 1);

if (!function_exists('haya_ini_flag')) {
    function haya_ini_flag($key)
    {
        $v = ini_get($key);
        if ($v === false) return false;
        $v = strtolower(trim((string)$v));
        return in_array($v, array('1', 'on', 'true', 'yes'), true);
    }
}

if (!function_exists('haya_cache_supports_apcu')) {
    function haya_cache_supports_apcu()
    {
        // 本物の APCu が有効なときだけ true
        $has = function_exists('apcu_store') && function_exists('apcu_fetch') && function_exists('apcu_delete');
        if (!$has) return false;
        $enabled = haya_ini_flag('apc.enabled') || haya_ini_flag('apcu.enabled');
        if (PHP_SAPI === 'cli') {
            $enabled = $enabled && haya_ini_flag('apc.enable_cli');
        }
        return $enabled;
    }
}

if (!function_exists('haya_cache_dir')) {
    function haya_cache_dir()
    {
        $dir = HAYA_CACHE_DIR;
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        return $dir;
    }
}

if (!function_exists('haya_cache_path')) {
    function haya_cache_path($key)
    {
        // 衝突を避けるためキーをハッシュ化（ファイル名安全化）
        return rtrim(haya_cache_dir(), '/')
            . '/' . hash('sha256', (string)$key) . '.cache';
    }
}

if (!function_exists('haya_cache_get')) {
    function haya_cache_get($key)
    {
        // 1) APCu
        if (haya_cache_supports_apcu()) {
            $ok = false;
            $val = apcu_fetch($key, $ok);
            if ($ok) return $val;
        }
        // 2) File（先頭行: 有効期限、2行目以降: payload）
        $path = haya_cache_path($key);
        if (!is_file($path)) return false;
        $fp = @fopen($path, 'rb');
        if (!$fp) return false;
        $meta = fgets($fp);
        $exp  = is_numeric($meta) ? (int)$meta : 0;
        if ($exp > 0 && time() > $exp) {
            fclose($fp);
            @unlink($path);
            return false;
        }
        $payload = stream_get_contents($fp);
        fclose($fp);
        return ($payload === '' || $payload === false) ? false : $payload;
    }
}

if (!function_exists('haya_cache_set')) {
    function haya_cache_set($key, $value, $ttl = HAYA_CACHE_TTL_DEFAULT)
    {
        $ttl = (int)$ttl;
        // 1) APCu
        if (haya_cache_supports_apcu()) {
            return apcu_store($key, $value, $ttl);
        }
        // 2) File（tmp→rename で原子的置換）
        $path = haya_cache_path($key);
        $tmp  = $path . '.tmp' . getmypid() . '.' . mt_rand();
        $exp  = $ttl > 0 ? time() + $ttl : 0;
        $data = $exp . "\n" . (string)$value;
        if (@file_put_contents($tmp, $data, LOCK_EX) === false) return false;
        @chmod($tmp, 0666);
        return @rename($tmp, $path);
    }
}

if (!function_exists('haya_cache_delete')) {
    function haya_cache_delete($key)
    {
        // 1) APCu
        if (haya_cache_supports_apcu()) {
            return apcu_delete($key);
        }
        // 2) File
        $path = haya_cache_path($key);
        return is_file($path) ? @unlink($path) : false;
    }
}

// ==== APCu polyfill（未導入環境での後方互換）====
// ※ 既存の apcu_* 直呼びを殺さないために、haya_cache_* に委譲します。
// ※ APCu が本当に有効なときは定義されません。
if (!function_exists('apcu_fetch')) {
    function apcu_fetch($key, &$success = null)
    {
        $val = haya_cache_get($key);
        $success = ($val !== false);
        return $val;
    }
}
if (!function_exists('apcu_store')) {
    function apcu_store($key, $var, $ttl = 0)
    {
        return haya_cache_set($key, $var, (int)$ttl);
    }
}
if (!function_exists('apcu_delete')) {
    function apcu_delete($key)
    {
        return haya_cache_delete($key);
    }
}
