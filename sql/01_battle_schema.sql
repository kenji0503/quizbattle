SET NAMES utf8mb4;

/*
  このSQLは「匿名で名前だけ入力してクイズバトルを行う」ための
  バトル用テーブル群を新規作成します。

  前提:
  - 問題マスタ q_mondai は既存資産を使う
  - 問題見出し q_head も既存資産を使う
  - q_mondai の形式は変更しない
    cate1, cate2, id, num, mondai, qa, qb, qc, qd, kaito, Kaisetu, url
*/

CREATE TABLE IF NOT EXISTS qb_group (
    gid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    status TINYINT NOT NULL DEFAULT 0,
    playcount INT NOT NULL DEFAULT 0,
    date DATE DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (gid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qb_battle (
    bid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    gid BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    make_uid BIGINT NOT NULL DEFAULT 0,
    status TINYINT NOT NULL DEFAULT 0,
    date DATE DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (bid),
    KEY idx_qb_battle_gid (gid),
    CONSTRAINT fk_qb_battle_group
        FOREIGN KEY (gid) REFERENCES qb_group (gid)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qb_battle_scope (
    battle_scope_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bid BIGINT UNSIGNED NOT NULL,
    cate1 INT NOT NULL,
    cate2 INT NOT NULL,
    qid INT NOT NULL,
    weight INT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (battle_scope_id),
    UNIQUE KEY uq_bid_scope (bid, cate1, cate2, qid),
    KEY idx_scope_bid (bid),
    CONSTRAINT fk_qb_scope_battle
        FOREIGN KEY (bid) REFERENCES qb_battle (bid)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qb_battle_participants (
    participant_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bid BIGINT UNSIGNED NOT NULL,
    gid BIGINT UNSIGNED NOT NULL,
    uid BIGINT NOT NULL,
    name VARCHAR(64) NOT NULL,
    avatar_type VARCHAR(32) DEFAULT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_ping DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (participant_id),
    UNIQUE KEY uq_participant_room_uid (bid, gid, uid),
    UNIQUE KEY uq_participant_room_name (bid, gid, name),
    KEY idx_participant_active (bid, gid, last_ping),
    KEY idx_participant_uid (uid),
    CONSTRAINT fk_qb_participant_battle
        FOREIGN KEY (bid) REFERENCES qb_battle (bid)
        ON DELETE CASCADE,
    CONSTRAINT fk_qb_participant_group
        FOREIGN KEY (gid) REFERENCES qb_group (gid)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qb_battle_lineup (
    lineup_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bid BIGINT UNSIGNED NOT NULL,
    order_no INT NOT NULL,
    cate1 INT NOT NULL,
    cate2 INT NOT NULL,
    id INT NOT NULL,
    num INT NOT NULL,
    display VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (lineup_id),
    UNIQUE KEY uq_lineup_order (bid, order_no),
    KEY idx_lineup_question (bid, cate1, cate2, id, num),
    CONSTRAINT fk_qb_lineup_battle
        FOREIGN KEY (bid) REFERENCES qb_battle (bid)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qb_battle_state (
    bid BIGINT UNSIGNED NOT NULL,
    gid BIGINT UNSIGNED NOT NULL,
    bnum INT NOT NULL DEFAULT 1,
    phase TINYINT NOT NULL DEFAULT 0,
    q_start_at BIGINT NOT NULL DEFAULT 0,
    reveal_at BIGINT NOT NULL DEFAULT 0,
    switch_at BIGINT NOT NULL DEFAULT 0,
    ts_ms BIGINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (bid, gid),
    KEY idx_state_phase (phase, switch_at),
    CONSTRAINT fk_qb_state_battle
        FOREIGN KEY (bid) REFERENCES qb_battle (bid)
        ON DELETE CASCADE,
    CONSTRAINT fk_qb_state_group
        FOREIGN KEY (gid) REFERENCES qb_group (gid)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qb_buzzes (
    buzz_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bid BIGINT UNSIGNED NOT NULL,
    gid BIGINT UNSIGNED NOT NULL,
    cate1 INT NOT NULL,
    cate2 INT NOT NULL,
    id INT NOT NULL,
    num INT NOT NULL,
    uid BIGINT NOT NULL,
    sentaku CHAR(1) NOT NULL,
    buzzed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (buzz_id),
    UNIQUE KEY uq_buzz_once (bid, gid, cate1, cate2, id, num, uid),
    KEY idx_buzz_question_time (bid, gid, cate1, cate2, id, num, buzzed_at),
    KEY idx_buzz_uid (uid),
    CONSTRAINT fk_qb_buzz_battle
        FOREIGN KEY (bid) REFERENCES qb_battle (bid)
        ON DELETE CASCADE,
    CONSTRAINT fk_qb_buzz_group
        FOREIGN KEY (gid) REFERENCES qb_group (gid)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS q_minaosi (
    review_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reqdate DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cate1 INT NOT NULL,
    cate2 INT NOT NULL,
    id INT NOT NULL,
    num INT NOT NULL,
    name VARCHAR(64) NOT NULL,
    comment VARCHAR(255) NOT NULL,
    taiou VARCHAR(64) DEFAULT NULL,
    status TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (review_id),
    KEY idx_q_minaosi_question (cate1, cate2, id, num, reqdate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
  問題サーバから取得したカテゴリキャッシュ
*/

CREATE TABLE IF NOT EXISTS qb_question_category (
    cate1 INT NOT NULL,
    cate2 INT NOT NULL,
    qid INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    kaisetu TEXT DEFAULT NULL,
    word VARCHAR(255) DEFAULT NULL,
    keyword VARCHAR(255) DEFAULT NULL,
    imageword VARCHAR(255) DEFAULT NULL,
    cnt INT NOT NULL DEFAULT 0,
    check_cnt INT NOT NULL DEFAULT 0,
    del TINYINT NOT NULL DEFAULT 0,
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cate1, cate2, qid),
    KEY idx_question_category_path (cate1, cate2),
    KEY idx_question_category_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
  問題サーバから取得した問題キャッシュ
  cate1, cate2, qid, qnum で一意
*/

CREATE TABLE IF NOT EXISTS qb_question_bank (
    cate1 INT NOT NULL,
    cate2 INT NOT NULL,
    qid INT NOT NULL,
    qnum INT NOT NULL,
    mondai TEXT NOT NULL,
    qa TEXT NOT NULL,
    qb TEXT NOT NULL,
    qc TEXT NOT NULL,
    qd TEXT NOT NULL,
    kaito CHAR(1) NOT NULL,
    kaisetu TEXT DEFAULT NULL,
    source_url TEXT DEFAULT NULL,
    level INT NOT NULL DEFAULT 1,
    note TEXT DEFAULT NULL,
    goodcnt INT NOT NULL DEFAULT 0,
    trycnt INT NOT NULL DEFAULT 0,
    del TINYINT NOT NULL DEFAULT 0,
    source_created_at DATETIME DEFAULT NULL,
    source_updated_at DATETIME DEFAULT NULL,
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cate1, cate2, qid, qnum),
    KEY idx_question_bank_theme (cate1, cate2, qid),
    KEY idx_question_bank_active (del, cate1, cate2, qid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
