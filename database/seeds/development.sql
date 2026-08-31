SET NAMES utf8mb4;
SET time_zone = '+00:00';

INSERT IGNORE INTO users (
    id, handle_name, email, password_hash, email_verified_at, role, status
) VALUES (
    1,
    '開発ユーザー',
    'developer@example.invalid',
    'development-login-disabled',
    UTC_TIMESTAMP(),
    'admin',
    'active'
);

INSERT IGNORE INTO oshis (
    id, name, short_name, name_kana, alternate_names, oshi_type, description,
    default_icon_key, default_color, status, created_by
) VALUES
    (1, 'アイドルA', 'アイドルA', 'あいどるえー', JSON_ARRAY('IDOL A'), 'person', 'ライブや配信を中心に活動するアイドル。', 'microphone', '#C74D67', 'active', 1),
    (2, 'グループB', 'グループB', 'ぐるーぷびー', JSON_ARRAY('GROUP B'), 'group', '音楽活動を行うグループ。', 'music', '#477BC4', 'active', 1),
    (3, '作品E', '作品E', 'さくひんいー', JSON_ARRAY('WORK E'), 'work', 'イベントや配信のあるアニメ作品。', 'play', '#6B62B5', 'active', 1);

INSERT IGNORE INTO user_oshis (
    id, user_id, oshi_id, custom_icon_key, custom_color, is_active
) VALUES
    (1, 1, 1, NULL, NULL, TRUE),
    (2, 1, 2, NULL, NULL, TRUE);

INSERT IGNORE INTO cards (
    id, card_type, title, summary, benefit_status, schedule_status,
    announced_period, placeholder_key, created_by, status
) VALUES
    (1, 'tour', 'WINTER TOUR 2026', '全国4都市を巡る冬のツアー。', 'unknown', 'scheduled', NULL, 'concert', 1, 'active'),
    (2, 'event', 'デビュー5周年記念イベント', 'デビュー5周年を記念したファンイベント。', 'available', 'scheduled', NULL, 'event', 1, 'active'),
    (3, 'streaming', '周年記念ライブ配信', '配信プラットフォームで公開予定。', 'none', 'undated', '2026年冬', 'streaming', 1, 'active');

INSERT IGNORE INTO card_oshis (card_id, oshi_id, relation_type) VALUES
    (1, 1, 'primary'),
    (2, 2, 'primary'),
    (3, 1, 'primary');

INSERT IGNORE INTO occurrences (
    id, card_id, label, starts_at, ends_at, date_precision,
    venue_name, venue_address, status, sort_order
) VALUES
    (1, 1, '東京公演', '2026-12-24 09:00:00', '2026-12-24 12:00:00', 'datetime', '東京ドーム', '東京都文京区', 'scheduled', 1),
    (2, 1, '大阪公演', '2026-12-27 09:00:00', '2026-12-27 12:00:00', 'datetime', '大阪イベントホール', '大阪府大阪市', 'scheduled', 2),
    (3, 2, NULL, '2026-09-06 04:00:00', '2026-09-06 07:00:00', 'datetime', 'イベントホール', '東京都', 'scheduled', 1),
    (4, 3, NULL, NULL, NULL, 'unknown', NULL, NULL, 'scheduled', 1);

INSERT IGNORE INTO user_schedules (
    id, user_id, occurrence_id, schedule_state
) VALUES
    (1, 1, 1, 'committed'),
    (2, 1, 3, 'checked');

INSERT IGNORE INTO card_sources (
    id, card_id, source_role, submitted_url, resolved_url,
    canonical_url, outbound_url, page_title, thumbnail_url,
    fetch_status, created_by
) VALUES
    (1, 1, 'primary', 'https://example.com/winter-tour', 'https://example.com/winter-tour', 'https://example.com/winter-tour', 'https://example.com/winter-tour', 'WINTER TOUR 2026 公式ページ', NULL, 'success', 1),
    (2, 2, 'primary', 'https://example.com/anniversary', 'https://example.com/anniversary', 'https://example.com/anniversary', 'https://example.com/anniversary', 'デビュー5周年記念イベント', NULL, 'success', 1);
