SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS card_edit_votes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    proposal_id BIGINT UNSIGNED NOT NULL,
    voter_id BIGINT UNSIGNED NOT NULL,
    decision VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_card_edit_votes_proposal_voter (proposal_id, voter_id),
    KEY idx_card_edit_votes_proposal_decision (proposal_id, decision),
    CONSTRAINT fk_card_edit_votes_proposal FOREIGN KEY (proposal_id) REFERENCES card_edit_proposals (id),
    CONSTRAINT fk_card_edit_votes_voter FOREIGN KEY (voter_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO schema_versions (version) VALUES ('0005_card_edit_votes');
