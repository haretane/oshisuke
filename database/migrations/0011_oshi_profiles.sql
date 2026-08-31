SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE oshis
    ADD COLUMN short_name VARCHAR(40) NULL AFTER name;

CREATE TABLE IF NOT EXISTS oshi_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    oshi_id BIGINT UNSIGNED NOT NULL,
    link_type VARCHAR(30) NOT NULL DEFAULT 'official',
    label VARCHAR(80) NOT NULL,
    url TEXT NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_oshi_links_oshi_order (oshi_id, sort_order),
    CONSTRAINT fk_oshi_links_oshi FOREIGN KEY (oshi_id) REFERENCES oshis (id),
    CONSTRAINT fk_oshi_links_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO schema_versions (version) VALUES ('0011_oshi_profiles');
