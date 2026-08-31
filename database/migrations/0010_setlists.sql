SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS setlists (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    occurrence_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_setlists_occurrence (occurrence_id),
    CONSTRAINT fk_setlists_occurrence FOREIGN KEY (occurrence_id) REFERENCES occurrences (id),
    CONSTRAINT fk_setlists_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_setlists_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS setlist_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    setlist_id BIGINT UNSIGNED NOT NULL,
    item_type VARCHAR(20) NOT NULL DEFAULT 'song',
    title VARCHAR(300) NOT NULL,
    sort_order INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_setlist_items_order (setlist_id, sort_order),
    CONSTRAINT fk_setlist_items_setlist FOREIGN KEY (setlist_id) REFERENCES setlists (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO schema_versions (version) VALUES ('0010_setlists');
