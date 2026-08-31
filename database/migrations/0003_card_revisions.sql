SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS card_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    card_id BIGINT UNSIGNED NOT NULL,
    changed_by BIGINT UNSIGNED NOT NULL,
    change_kind VARCHAR(30) NOT NULL DEFAULT 'owner_update',
    before_data JSON NOT NULL,
    after_data JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_card_revisions_card_created (card_id, created_at),
    CONSTRAINT fk_card_revisions_card FOREIGN KEY (card_id) REFERENCES cards (id),
    CONSTRAINT fk_card_revisions_changed_by FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO schema_versions (version) VALUES ('0003_card_revisions');
