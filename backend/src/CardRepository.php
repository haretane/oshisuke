<?php

declare(strict_types=1);

function isoDateTime(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $timestamp = strtotime($value . ' UTC');
    return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
}

function cardList(PDO $pdo, ?int $userId, string $sort, ?int $oshiId = null, bool $followingOnly = true): array
{
    $orderBy = match ($sort) {
        'date' => 'CASE WHEN oc.starts_at IS NULL THEN 1 ELSE 0 END, oc.starts_at, c.id',
        'popular' => 'my_schedule_count DESC, c.updated_at DESC, c.id DESC',
        default => 'c.updated_at DESC, c.id DESC, oc.sort_order',
    };

    $sql = sprintf(
        "SELECT
            c.id AS card_id,
            c.card_type,
            c.title,
            c.summary,
            c.schedule_status,
            c.announced_period,
            c.placeholder_key,
            c.updated_at AS card_updated_at,
            oc.id AS occurrence_id,
            oc.label AS occurrence_label,
            oc.starts_at,
            oc.ends_at,
            oc.venue_name,
            oc.status AS occurrence_status,
            o.id AS oshi_id,
            o.name AS oshi_name,
            COALESCE(uo.custom_icon_key, o.default_icon_key) AS icon_key,
            COALESCE(uo.custom_color, o.default_color) AS color,
            us.schedule_state AS my_schedule_state,
            cs.page_title AS source_title,
            cs.outbound_url,
            cs.thumbnail_url AS source_thumbnail,
            SUBSTRING_INDEX(SUBSTRING_INDEX(cs.outbound_url, '/', 3), '//', -1) AS source_domain,
            (
                SELECT COUNT(DISTINCT totals.user_id)
                FROM user_schedules totals
                INNER JOIN occurrences total_oc ON total_oc.id = totals.occurrence_id
                WHERE total_oc.card_id = c.id
            ) AS my_schedule_count
        FROM cards c
        INNER JOIN card_oshis co ON co.card_id = c.id
        INNER JOIN oshis o ON o.id = co.oshi_id AND o.status = 'active'
        LEFT JOIN user_oshis uo ON uo.oshi_id = o.id AND uo.user_id = :following_user_id AND uo.is_active = 1
        LEFT JOIN occurrences oc ON oc.card_id = c.id
        LEFT JOIN user_schedules us ON us.occurrence_id = oc.id AND us.user_id = :schedule_user_id
        LEFT JOIN card_sources cs ON cs.card_id = c.id AND cs.source_role = 'primary' AND cs.fetch_status = 'success'
        WHERE c.status = 'active'
          AND (:following_only = 0 OR uo.id IS NOT NULL)
          AND (:oshi_filter = 0 OR o.id = :oshi_filter_compare)
        ORDER BY %s
        LIMIT 100",
        $orderBy
    );

    $statement = $pdo->prepare($sql);
    $statement->execute([
        'following_user_id' => $userId ?? 0,
        'schedule_user_id' => $userId ?? 0,
        'following_only' => $followingOnly ? 1 : 0,
        'oshi_filter' => $oshiId ?? 0,
        'oshi_filter_compare' => $oshiId ?? 0,
    ]);

    return groupCardRows($statement->fetchAll());
}

function scheduleList(PDO $pdo, int $userId, string $state, string $period): array
{
    $periodCondition = match ($period) {
        'past' => 'oc.starts_at < UTC_TIMESTAMP()',
        'all' => '1 = 1',
        default => '(oc.starts_at >= UTC_TIMESTAMP() OR oc.starts_at IS NULL)',
    };

    $sql = sprintf(
        "SELECT
            c.id AS card_id,
            c.card_type,
            c.title,
            c.summary,
            c.schedule_status,
            c.announced_period,
            c.placeholder_key,
            c.updated_at AS card_updated_at,
            oc.id AS occurrence_id,
            oc.label AS occurrence_label,
            oc.starts_at,
            oc.ends_at,
            oc.venue_name,
            oc.status AS occurrence_status,
            o.id AS oshi_id,
            o.name AS oshi_name,
            COALESCE(uo.custom_icon_key, o.default_icon_key) AS icon_key,
            COALESCE(uo.custom_color, o.default_color) AS color,
            us.schedule_state AS my_schedule_state,
            NULL AS source_title,
            NULL AS outbound_url,
            NULL AS source_thumbnail,
            NULL AS source_domain,
            0 AS my_schedule_count
        FROM user_schedules us
        INNER JOIN occurrences oc ON oc.id = us.occurrence_id
        INNER JOIN cards c ON c.id = oc.card_id AND c.status = 'active'
        INNER JOIN card_oshis co ON co.card_id = c.id
        INNER JOIN oshis o ON o.id = co.oshi_id AND o.status = 'active'
        LEFT JOIN user_oshis uo ON uo.oshi_id = o.id AND uo.user_id = us.user_id
        WHERE us.user_id = :user_id
          AND us.schedule_state = :schedule_state
          AND %s
        ORDER BY CASE WHEN oc.starts_at IS NULL THEN 1 ELSE 0 END, oc.starts_at, oc.id",
        $periodCondition
    );

    $statement = $pdo->prepare($sql);
    $statement->execute([
        'user_id' => $userId,
        'schedule_state' => $state,
    ]);

    return groupCardRows($statement->fetchAll(), true);
}

function cardDetail(PDO $pdo, int $cardId, ?int $viewerUserId): ?array
{
    $cardStatement = $pdo->prepare(
        "SELECT id, card_type, title, summary, schedule_status, announced_period,
                placeholder_key, created_by, updated_at
         FROM cards
         WHERE id = :card_id AND status = 'active'"
    );
    $cardStatement->execute(['card_id' => $cardId]);
    $cardRow = $cardStatement->fetch();
    if ($cardRow === false) {
        return null;
    }

    $oshiStatement = $pdo->prepare(
        "SELECT o.id, o.name, o.default_icon_key, o.default_color,
                uo.custom_icon_key, uo.custom_color
         FROM card_oshis co
         INNER JOIN oshis o ON o.id = co.oshi_id AND o.status = 'active'
         LEFT JOIN user_oshis uo ON uo.oshi_id = o.id AND uo.user_id = :viewer_user_id
         WHERE co.card_id = :card_id
         ORDER BY CASE co.relation_type WHEN 'primary' THEN 0 ELSE 1 END, o.id"
    );
    $oshiStatement->execute(['viewer_user_id' => $viewerUserId ?? 0, 'card_id' => $cardId]);
    $oshis = array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'icon_key' => $row['custom_icon_key'] ?? $row['default_icon_key'],
        'color' => $row['custom_color'] ?? $row['default_color'],
    ], $oshiStatement->fetchAll());

    $occurrenceStatement = $pdo->prepare(
        "SELECT oc.id, oc.label, oc.starts_at, oc.ends_at, oc.venue_name,
                oc.venue_address, oc.status, us.schedule_state
         FROM occurrences oc
         LEFT JOIN user_schedules us
           ON us.occurrence_id = oc.id AND us.user_id = :viewer_user_id
         WHERE oc.card_id = :card_id
         ORDER BY CASE WHEN oc.starts_at IS NULL THEN 1 ELSE 0 END,
                  oc.starts_at, oc.sort_order, oc.id"
    );
    $occurrenceStatement->execute(['viewer_user_id' => $viewerUserId ?? 0, 'card_id' => $cardId]);
    $occurrences = array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'label' => $row['label'],
        'starts_at' => isoDateTime($row['starts_at']),
        'ends_at' => isoDateTime($row['ends_at']),
        'display_timezone' => 'Asia/Tokyo',
        'venue_name' => $row['venue_name'],
        'venue_address' => $row['venue_address'],
        'status' => $row['status'],
        'my_schedule_state' => $row['schedule_state'],
    ], $occurrenceStatement->fetchAll());

    $sourceStatement = $pdo->prepare(
        "SELECT id, source_role, page_title, outbound_url, thumbnail_url
         FROM card_sources
         WHERE card_id = :card_id AND fetch_status = 'success'
         ORDER BY CASE source_role WHEN 'primary' THEN 0 ELSE 1 END, id"
    );
    $sourceStatement->execute(['card_id' => $cardId]);
    $sources = array_map(static function (array $row): array {
        $host = parse_url($row['outbound_url'], PHP_URL_HOST);
        return [
            'id' => (int) $row['id'],
            'role' => $row['source_role'],
            'page_title' => $row['page_title'],
            'domain' => is_string($host) ? $host : '',
            'outbound_url' => $row['outbound_url'],
            'thumbnail_url' => $row['thumbnail_url'],
        ];
    }, $sourceStatement->fetchAll());

    $canEdit = $viewerUserId !== null && (int) $cardRow['created_by'] === $viewerUserId;
    $canProposeEdit = false;
    $editRequiresEmail = false;
    if ($viewerUserId !== null && !$canEdit) {
        $viewerStatement = $pdo->prepare('SELECT email_verified_at FROM users WHERE id = :user_id');
        $viewerStatement->execute(['user_id' => $viewerUserId]);
        $viewer = $viewerStatement->fetch();
        $canProposeEdit = $viewer !== false && $viewer['email_verified_at'] !== null;
        $editRequiresEmail = !$canProposeEdit;
    }

    return [
        'id' => (int) $cardRow['id'],
        'type' => $cardRow['card_type'],
        'title' => $cardRow['title'],
        'summary' => $cardRow['summary'],
        'schedule_status' => $cardRow['schedule_status'],
        'announced_period' => $cardRow['announced_period'],
        'thumbnail' => [
            'url' => $sources[0]['thumbnail_url'] ?? null,
            'placeholder_key' => $cardRow['placeholder_key'],
        ],
        'oshis' => $oshis,
        'occurrences' => $occurrences,
        'sources' => $sources,
        'hashtags' => [],
        'can_edit' => $canEdit,
        'can_propose_edit' => $canProposeEdit,
        'edit_requires_email' => $editRequiresEmail,
        'updated_at' => isoDateTime($cardRow['updated_at']),
    ];
}

function pendingCardEditProposals(PDO $pdo, int $cardId, int $viewerUserId): array
{
    $viewerStatement = $pdo->prepare(
        'SELECT email_verified_at, created_at FROM users WHERE id = :user_id'
    );
    $viewerStatement->execute(['user_id' => $viewerUserId]);
    $viewer = $viewerStatement->fetch();
    $viewerEligible = $viewer !== false
        && $viewer['email_verified_at'] !== null
        && strtotime($viewer['created_at'] . ' UTC') <= time() - 7 * 86400;

    $statement = $pdo->prepare(
        "SELECT p.id, p.proposed_by, p.before_data, p.proposed_data,
                p.correct_votes, p.current_votes, p.created_at,
                v.decision AS my_vote
         FROM card_edit_proposals p
         LEFT JOIN card_edit_votes v
           ON v.proposal_id = p.id AND v.voter_id = :viewer_user_id
         WHERE p.card_id = :card_id AND p.status = 'pending'
         ORDER BY p.created_at, p.id"
    );
    $statement->execute(['viewer_user_id' => $viewerUserId, 'card_id' => $cardId]);

    return array_map(static function (array $row) use ($viewerUserId, $viewerEligible): array {
        return [
            'id' => (int) $row['id'],
            'before' => json_decode($row['before_data'], true, 512, JSON_THROW_ON_ERROR),
            'proposed' => json_decode($row['proposed_data'], true, 512, JSON_THROW_ON_ERROR),
            'proposal_votes' => (int) $row['correct_votes'],
            'current_votes' => (int) $row['current_votes'],
            'my_vote' => $row['my_vote'],
            'can_vote' => $viewerEligible && (int) $row['proposed_by'] !== $viewerUserId && $row['my_vote'] === null,
            'created_at' => isoDateTime($row['created_at']),
        ];
    }, $statement->fetchAll());
}

function groupCardRows(array $rows, bool $separateOccurrences = false): array
{
    $cards = [];

    foreach ($rows as $row) {
        $cardKey = $separateOccurrences
            ? $row['card_id'] . ':' . ($row['occurrence_id'] ?? 'undated')
            : (string) $row['card_id'];

        if (!isset($cards[$cardKey])) {
            $cards[$cardKey] = [
                'id' => (int) $row['card_id'],
                'type' => $row['card_type'],
                'title' => $row['title'],
                'summary' => $row['summary'],
                'schedule_status' => $row['schedule_status'],
                'announced_period' => $row['announced_period'],
                'thumbnail' => [
                    'url' => $row['source_thumbnail'],
                    'placeholder_key' => $row['placeholder_key'],
                ],
                'oshis' => [],
                'occurrences' => [],
                'primary_source' => $row['outbound_url'] === null ? null : [
                    'page_title' => $row['source_title'],
                    'domain' => $row['source_domain'],
                    'outbound_url' => $row['outbound_url'],
                ],
                'my_schedule_count' => (int) $row['my_schedule_count'],
                'updated_at' => isoDateTime($row['card_updated_at']),
            ];
        }

        $oshiId = (int) $row['oshi_id'];
        $cards[$cardKey]['oshis'][$oshiId] = [
            'id' => $oshiId,
            'name' => $row['oshi_name'],
            'icon_key' => $row['icon_key'],
            'color' => $row['color'],
        ];

        if ($row['occurrence_id'] !== null) {
            $occurrenceId = (int) $row['occurrence_id'];
            $cards[$cardKey]['occurrences'][$occurrenceId] = [
                'id' => $occurrenceId,
                'label' => $row['occurrence_label'],
                'starts_at' => isoDateTime($row['starts_at']),
                'ends_at' => isoDateTime($row['ends_at']),
                'display_timezone' => 'Asia/Tokyo',
                'venue_name' => $row['venue_name'],
                'status' => $row['occurrence_status'],
                'my_schedule_state' => $row['my_schedule_state'],
            ];
        }
    }

    return array_values(array_map(
        static function (array $card): array {
            $card['oshis'] = array_values($card['oshis']);
            $card['occurrences'] = array_values($card['occurrences']);
            return $card;
        },
        $cards
    ));
}
