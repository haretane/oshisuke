SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS url_previews (
    token CHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    submitted_url TEXT NOT NULL,
    resolved_url TEXT NOT NULL,
    page_title VARCHAR(300) NULL,
    image_mime VARCHAR(50) NULL,
    image_data MEDIUMBLOB NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (token),
    KEY idx_url_previews_user_created (user_id, created_at),
    KEY idx_url_previews_expires (expires_at),
    CONSTRAINT fk_url_previews_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS source_images (
    source_id BIGINT UNSIGNED NOT NULL,
    mime_type VARCHAR(50) NOT NULL,
    image_data MEDIUMBLOB NOT NULL,
    sha256 CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (source_id),
    CONSTRAINT fk_source_images_source FOREIGN KEY (source_id) REFERENCES card_sources (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO schema_versions (version) VALUES ('0009_url_previews');
