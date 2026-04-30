<?php

/* ===== avatar 決定ユーティリティ（最小構成） ===== */
function getTypes(): array
{
    return ['saturn', 'sun', 'earth', 'jupiter', 'alien', 'martian', 'human', 'satellite', 'rocket', 'ufo', 'robot'];
}
function fetchMyAvatar(PDO $pdo, int $gid, int $bid, int $uid): ?string
{
    $st = $pdo->prepare("SELECT avatar_type FROM qb_battle_participants WHERE gid=? AND bid=? AND uid=? LIMIT 1");
    $st->execute([$gid, $bid, $uid]);
    $v = $st->fetchColumn();
    return $v !== false ? ($v ?: null) : null;
}
function fetchPrevTypeInChain(PDO $pdo, int $gid, int $currentBid, int $uid, int $rootBid = 0, int $prevBid = 0): ?string
{
    if ($rootBid > 0) {
        $st = $pdo->prepare("
            SELECT avatar_type FROM qb_battle_participants
            WHERE gid=? AND uid=? AND bid>=? AND bid<? AND avatar_type IS NOT NULL
            ORDER BY bid DESC LIMIT 1
        ");
        $st->execute([$gid, $uid, $rootBid, $currentBid]);
        $t = $st->fetchColumn();
        if ($t) return $t;
    }
    if ($prevBid > 0) {
        $st = $pdo->prepare("
            SELECT avatar_type FROM qb_battle_participants
            WHERE gid=? AND uid=? AND bid=? AND avatar_type IS NOT NULL
            LIMIT 1
        ");
        $st->execute([$gid, $uid, $prevBid]);
        $t = $st->fetchColumn();
        if ($t) return $t;
    }
    return null;
}
function pickBalancedType(PDO $pdo, int $gid, int $bid, array $types): string
{
    $st = $pdo->prepare("
        SELECT avatar_type, COUNT(*) c
          FROM qb_battle_participants
         WHERE gid=? AND bid=? AND avatar_type IS NOT NULL
         GROUP BY avatar_type
    ");
    $st->execute([$gid, $bid]);

    $counts = array_fill_keys($types, 0);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $t = (string)$r['avatar_type'];
        if (isset($counts[$t])) $counts[$t] = (int)$r['c'];
    }
    $min = min($counts ?: [0]);
    $candidates = [];
    foreach ($counts as $t => $n) if ($n === $min) $candidates[] = $t;
    if (!$candidates) $candidates = $types;
    shuffle($candidates);
    return $candidates[0];
}
function ensureAvatarInParticipants(PDO $pdo, int $gid, int $bid, int $uid, string $name, int $rootBid = 0, int $prevBid = 0): string
{
    if ($a = fetchMyAvatar($pdo, $gid, $bid, $uid)) return $a;

    $lockName = sprintf('lock_avatar_gid_%d_bid_%d', $gid, $bid);
    $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ",5)");
    try {
        if ($a = fetchMyAvatar($pdo, $gid, $bid, $uid)) return $a;

        $types = getTypes();
        $choice = fetchPrevTypeInChain($pdo, $gid, $bid, $uid, $rootBid, $prevBid) ?? pickBalancedType($pdo, $gid, $bid, $types);

        $up = $pdo->prepare("
            UPDATE qb_battle_participants
               SET avatar_type=?
             WHERE gid=? AND bid=? AND uid=? AND (avatar_type IS NULL OR avatar_type='')
             LIMIT 1
        ");
        $up->execute([$choice, $gid, $bid, $uid]);
        return fetchMyAvatar($pdo, $gid, $bid, $uid) ?: $choice;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");
    }
}
