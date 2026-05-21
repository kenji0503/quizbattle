SET @qb_db = DATABASE();

SET @qb_sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
              FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = @qb_db
               AND TABLE_NAME = 'qb_battle_lineup'
               AND COLUMN_NAME = 'q_start_at_ms'
        ),
        'SELECT 1',
        'ALTER TABLE qb_battle_lineup ADD COLUMN q_start_at_ms BIGINT NOT NULL DEFAULT 0 AFTER display'
    )
);
PREPARE qb_stmt FROM @qb_sql;
EXECUTE qb_stmt;
DEALLOCATE PREPARE qb_stmt;

SET @qb_sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
              FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = @qb_db
               AND TABLE_NAME = 'qb_battle_lineup'
               AND COLUMN_NAME = 'reveal_at_ms'
        ),
        'SELECT 1',
        'ALTER TABLE qb_battle_lineup ADD COLUMN reveal_at_ms BIGINT NOT NULL DEFAULT 0 AFTER q_start_at_ms'
    )
);
PREPARE qb_stmt FROM @qb_sql;
EXECUTE qb_stmt;
DEALLOCATE PREPARE qb_stmt;

SET @qb_sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
              FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = @qb_db
               AND TABLE_NAME = 'qb_battle_lineup'
               AND COLUMN_NAME = 'switch_at_ms'
        ),
        'SELECT 1',
        'ALTER TABLE qb_battle_lineup ADD COLUMN switch_at_ms BIGINT NOT NULL DEFAULT 0 AFTER reveal_at_ms'
    )
);
PREPARE qb_stmt FROM @qb_sql;
EXECUTE qb_stmt;
DEALLOCATE PREPARE qb_stmt;
