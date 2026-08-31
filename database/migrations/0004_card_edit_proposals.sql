SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS card_edit_proposals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    card_id BIGINT UNSIGNED NOT NULL,
    proposed_by BIGINT UNSIGNED NOT NULL,
    before_data JSON NOT NULL,
    proposed_data JSON NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    correct_votes INT UNSIGNED NOT NULL DEFAULT 0,
    current_votes INT UNSIGNED NOT NULL DEFAULT 0,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_card_edit_proposals_card_status (card_id, status, created_at),
    KEY idx_card_edit_proposals_user_created (proposed_by, created_at),
    CONSTRAINT fk_card_edit_proposals_card FOREIGN KEY (card_id) REFERENCES cards (id),
    CONSTRAINT fk_card_edit_proposals_user FOREIGN KEY (proposed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO schema_versions (version) VALUES ('0004_card_edit_proposals');
