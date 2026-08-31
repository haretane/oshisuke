SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    handle_name VARCHAR(50) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email_verified_at DATETIME NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS oshis (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    name_kana VARCHAR(150) NULL,
    alternate_names JSON NULL,
    oshi_type VARCHAR(30) NOT NULL,
    description TEXT NULL,
    default_icon_key VARCHAR(50) NOT NULL,
    default_color CHAR(7) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_oshis_name (name),
    KEY idx_oshis_name_kana (name_kana),
    KEY idx_oshis_status (status),
    CONSTRAINT fk_oshis_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS user_oshis (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    oshi_id BIGINT UNSIGNED NOT NULL,
    custom_icon_key VARCHAR(50) NULL,
    custom_color CHAR(7) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_oshis_user_oshi (user_id, oshi_id),
    KEY idx_user_oshis_active (user_id, is_active),
    CONSTRAINT fk_user_oshis_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_user_oshis_oshi FOREIGN KEY (oshi_id) REFERENCES oshis (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS cards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    card_type VARCHAR(30) NOT NULL,
    title VARCHAR(200) NOT NULL,
    summary TEXT NULL,
    benefit_status VARCHAR(20) NOT NULL DEFAULT 'unknown',
    schedule_status VARCHAR(20) NOT NULL DEFAULT 'undated',
    announced_period VARCHAR(100) NULL,
    placeholder_key VARCHAR(50) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cards_status_updated (status, updated_at),
    KEY idx_cards_schedule_status (schedule_status),
    CONSTRAINT fk_cards_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS card_oshis (
    card_id BIGINT UNSIGNED NOT NULL,
    oshi_id BIGINT UNSIGNED NOT NULL,
    relation_type VARCHAR(30) NOT NULL DEFAULT 'primary',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (card_id, oshi_id),
    KEY idx_card_oshis_oshi (oshi_id, card_id),
    CONSTRAINT fk_card_oshis_card FOREIGN KEY (card_id) REFERENCES cards (id),
    CONSTRAINT fk_card_oshis_oshi FOREIGN KEY (oshi_id) REFERENCES oshis (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS occurrences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    card_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(150) NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    date_precision VARCHAR(20) NOT NULL DEFAULT 'unknown',
    venue_name VARCHAR(200) NULL,
    venue_address VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_occurrences_card_sort (card_id, sort_order),
    KEY idx_occurrences_starts_at (starts_at),
    CONSTRAINT fk_occurrences_card FOREIGN KEY (card_id) REFERENCES cards (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS user_schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    occurrence_id BIGINT UNSIGNED NOT NULL,
    schedule_state VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_schedules_user_occurrence (user_id, occurrence_id),
    KEY idx_user_schedules_state (user_id, schedule_state, updated_at),
    CONSTRAINT fk_user_schedules_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_user_schedules_occurrence FOREIGN KEY (occurrence_id) REFERENCES occurrences (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS card_sources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    card_id BIGINT UNSIGNED NOT NULL,
    source_role VARCHAR(20) NOT NULL DEFAULT 'related',
    submitted_url TEXT NOT NULL,
    resolved_url TEXT NULL,
    canonical_url TEXT NULL,
    outbound_url TEXT NOT NULL,
    page_title VARCHAR(300) NULL,
    thumbnail_url TEXT NULL,
    fetch_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_card_sources_card_role (card_id, source_role),
    CONSTRAINT fk_card_sources_card FOREIGN KEY (card_id) REFERENCES cards (id),
    CONSTRAINT fk_card_sources_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO schema_versions (version) VALUES ('0002_core_tables');

