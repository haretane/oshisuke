<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Http.php';
require_once dirname(__DIR__) . '/src/CardRepository.php';
require_once dirname(__DIR__) . '/src/NotificationRepository.php';
require_once dirname(__DIR__) . '/src/SafeUrlPreview.php';
require_once dirname(__DIR__) . '/src/Session.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cross-Origin-Resource-Policy: same-origin');
header('Cache-Control: no-store');
startApiSession();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($method === 'GET' && $path === '/api/health') {
    jsonResponse(['data' => ['status' => 'ok']]);
}

if ($method === 'GET' && $path === '/api/session') {
    $userId = currentUserId();
    $user = null;

    if ($userId !== null) {
        $statement = database()->prepare(
            "SELECT id, handle_name, role FROM users WHERE id = :id AND status = 'active'"
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        if ($row !== false) {
            $user = ['id' => (int) $row['id'], 'handle_name' => $row['handle_name'], 'role' => $row['role']];
        }
    }

    jsonResponse([
        'data' => [
            'authenticated' => $user !== null,
            'user' => $user,
            'csrf_token' => csrfToken(),
        ],
    ]);
}

if ($method === 'POST' && $path === '/api/auth/register') {
    requireCsrfToken();
    assertAuthRateLimit();
    $body = jsonRequestBody();
    $handleName = trim((string) ($body['handle_name'] ?? ''));
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');
    $fields = [];

    if (preg_match('/\A.{1,50}\z/u', $handleName) !== 1) {
        $fields['handle_name'] = 'HNは1〜50文字で入力してください。';
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
        $fields['email'] = 'メールアドレスを確認してください。';
    }
    if (strlen($password) < 10 || strlen($password) > 128) {
        $fields['password'] = 'パスワードは10〜128文字で入力してください。';
    }
    if ($fields !== []) {
        jsonResponse([
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => '入力内容を確認してください。',
                'fields' => $fields,
            ],
        ], 422);
    }

    try {
        $statement = database()->prepare(
            "INSERT INTO users (handle_name, email, password_hash, role, status)
             VALUES (:handle_name, :email, :password_hash, 'user', 'active')"
        );
        $statement->execute([
            'handle_name' => $handleName,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $userId = (int) database()->lastInsertId();
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            errorResponse('EMAIL_ALREADY_USED', 'このメールアドレスは使用できません。', 409);
        }
        error_log($exception->getMessage());
        errorResponse('INTERNAL_ERROR', 'アカウントを作成できませんでした。', 500);
    }

    establishUserSession($userId);
    jsonResponse([
        'data' => [
            'user' => ['id' => $userId, 'handle_name' => $handleName, 'role' => 'user'],
            'csrf_token' => csrfToken(),
        ],
    ], 201);
}

if ($method === 'POST' && $path === '/api/auth/login') {
    requireCsrfToken();
    assertAuthRateLimit();
    $body = jsonRequestBody();
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');

    $statement = database()->prepare(
        "SELECT id, handle_name, password_hash, role
         FROM users WHERE email = :email AND status = 'active' LIMIT 1"
    );
    $statement->execute(['email' => $email]);
    $user = $statement->fetch();

    if ($user === false || !password_verify($password, $user['password_hash'])) {
        errorResponse('INVALID_CREDENTIALS', 'メールアドレスまたはパスワードが違います。', 401);
    }

    $userId = (int) $user['id'];
    establishUserSession($userId);
    jsonResponse([
        'data' => [
            'user' => ['id' => $userId, 'handle_name' => $user['handle_name'], 'role' => $user['role']],
            'csrf_token' => csrfToken(),
        ],
    ]);
}

if ($method === 'POST' && $path === '/api/auth/logout') {
    requireAuthenticatedUser();
    requireCsrfToken();
    destroyUserSession();
    http_response_code(204);
    exit;
}

if ($method === 'POST' && $path === '/api/oshis') {
    $userId = requireAuthenticatedUser();
    requireCsrfToken();
    $body = jsonRequestBody();
    $name = trim((string) ($body['name'] ?? ''));
    $shortName = trim((string) ($body['short_name'] ?? ''));
    $nameKana = trim((string) ($body['name_kana'] ?? ''));
    $type = (string) ($body['type'] ?? '');
    $description = trim((string) ($body['description'] ?? ''));
    $iconKey = (string) ($body['icon_key'] ?? 'microphone');
    $color = strtoupper((string) ($body['color'] ?? ''));
    $fields = [];
    if (preg_match('/\A.{1,150}\z/us', $name) !== 1) $fields['name'] = '正式名称は1〜150文字で入力してください。';
    if ($shortName !== '' && preg_match('/\A.{1,20}\z/us', $shortName) !== 1) $fields['short_name'] = '略称は20文字以内で入力してください。';
    if ($nameKana !== '' && preg_match('/\A.{1,150}\z/us', $nameKana) !== 1) $fields['name_kana'] = '読み方は150文字以内で入力してください。';
    if (!in_array($type, ['person', 'group', 'work', 'character', 'other'], true)) $fields['type'] = '種類を選択してください。';
    if (preg_match('/\A.{0,2000}\z/us', $description) !== 1) $fields['description'] = '紹介は2000文字以内で入力してください。';
    if (!in_array($iconKey, ['microphone', 'music', 'play'], true)) $fields['icon_key'] = 'アイコンを選択してください。';
    if (preg_match('/\A#[0-9A-F]{6}\z/', $color) !== 1) $fields['color'] = '色を選択してください。';
    if ($fields !== []) jsonResponse(['error' => ['code' => 'VALIDATION_ERROR', 'message' => '入力内容を確認してください。', 'fields' => $fields]], 422);

    $pdo = database();
    $userStatement = $pdo->prepare('SELECT email_verified_at, created_at, role FROM users WHERE id = :id');
    $userStatement->execute(['id' => $userId]);
    $user = $userStatement->fetch();
    $development = (getenv('APP_ENV') ?: '') === 'development';
    if (!$development && $user['email_verified_at'] === null) {
        errorResponse('EMAIL_VERIFICATION_REQUIRED', '推しの新規作成にはメール認証が必要です。', 403);
    }
    $accountAge = time() - (strtotime($user['created_at'] . ' UTC') ?: time());
    $dailyLimit = $accountAge < 7 * 86400 ? 3 : 10;
    if (in_array($user['role'], ['admin', 'moderator'], true)) $dailyLimit = 100;
    $countStatement = $pdo->prepare('SELECT COUNT(*) FROM oshis WHERE created_by = :user_id AND created_at >= UTC_DATE()');
    $countStatement->execute(['user_id' => $userId]);
    if ((int) $countStatement->fetchColumn() >= $dailyLimit) {
        errorResponse('DAILY_OSHI_LIMIT_REACHED', "本日の推し作成は上限{$dailyLimit}件に達しました。", 429);
    }
    $duplicateStatement = $pdo->prepare("SELECT id FROM oshis WHERE status = 'active' AND (name = :name OR (:name_kana <> '' AND name_kana = :name_kana_compare)) LIMIT 1");
    $duplicateStatement->execute(['name' => $name, 'name_kana' => $nameKana, 'name_kana_compare' => $nameKana]);
    if ($duplicateStatement->fetch() !== false) errorResponse('OSHI_ALREADY_EXISTS', '同じ名前または読み方の推しがすでにあります。検索結果をご確認ください。', 409);

    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            "INSERT INTO oshis (name, short_name, name_kana, alternate_names, oshi_type, description, default_icon_key, default_color, status, created_by)
             VALUES (:name, :short_name, :name_kana, JSON_ARRAY(), :oshi_type, :description, :icon_key, :color, 'active', :created_by)"
        );
        $statement->execute(['name' => $name, 'short_name' => $shortName === '' ? null : $shortName, 'name_kana' => $nameKana === '' ? null : $nameKana, 'oshi_type' => $type, 'description' => $description === '' ? null : $description, 'icon_key' => $iconKey, 'color' => $color, 'created_by' => $userId]);
        $oshiId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO user_oshis (user_id, oshi_id, is_active) VALUES (:user_id, :oshi_id, 1)')->execute(['user_id' => $userId, 'oshi_id' => $oshiId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($exception->getMessage());
        errorResponse('INTERNAL_ERROR', '推しプロフィールを作成できませんでした。', 500);
    }
    jsonResponse(['data' => ['id' => $oshiId]], 201);
}

if ($method === 'GET' && $path === '/api/oshis') {
    $keyword = trim((string) ($_GET['q'] ?? ''));
    $type = trim((string) ($_GET['type'] ?? ''));

    if (strlen($keyword) > 300) {
        errorResponse('VALIDATION_ERROR', '検索文字は100文字以内で入力してください。', 422);
    }

    $allowedTypes = ['person', 'group', 'work', 'character', 'other'];
    if ($type !== '' && !in_array($type, $allowedTypes, true)) {
        errorResponse('VALIDATION_ERROR', '指定された推しの種類は使用できません。', 422);
    }

    $conditions = ["o.status = 'active'"];
    $parameters = ['viewer_user_id' => currentUserId() ?? 0];

    if ($keyword !== '') {
        $conditions[] = '(
            LOCATE(:keyword_name, o.name) > 0
            OR LOCATE(:keyword_short, COALESCE(o.short_name, \'\')) > 0
            OR LOCATE(:keyword_kana, COALESCE(o.name_kana, \'\')) > 0
            OR LOCATE(:keyword_alias, COALESCE(CAST(o.alternate_names AS CHAR), \'\')) > 0
        )';
        $parameters['keyword_name'] = $keyword;
        $parameters['keyword_short'] = $keyword;
        $parameters['keyword_kana'] = $keyword;
        $parameters['keyword_alias'] = $keyword;
    }

    if ($type !== '') {
        $conditions[] = 'o.oshi_type = :oshi_type';
        $parameters['oshi_type'] = $type;
    }

    $sql = sprintf(
        'SELECT
            o.id,
            o.name,
            o.short_name,
            o.name_kana,
            o.oshi_type,
            o.default_icon_key,
            o.default_color,
            EXISTS(
                SELECT 1 FROM user_oshis mine
                WHERE mine.oshi_id = o.id
                  AND mine.user_id = :viewer_user_id
                  AND mine.is_active = 1
            ) AS is_following,
            COUNT(CASE WHEN uo.is_active = 1 THEN 1 END) AS follower_count
        FROM oshis o
        LEFT JOIN user_oshis uo ON uo.oshi_id = o.id
        WHERE %s
        GROUP BY
            o.id,
            o.name,
            o.short_name,
            o.name_kana,
            o.oshi_type,
            o.default_icon_key,
            o.default_color,
            o.created_at
        ORDER BY follower_count DESC, o.created_at DESC, o.id DESC
        LIMIT 20',
        implode(' AND ', $conditions)
    );

    try {
        $statement = database()->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        errorResponse('INTERNAL_ERROR', '推し情報を取得できませんでした。', 500);
    }

    $typeLabels = [
        'person' => '人物',
        'group' => 'グループ',
        'work' => '作品',
        'character' => 'キャラクター',
        'other' => 'その他',
    ];

    $data = array_map(
        static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'short_name' => $row['short_name'],
            'name_kana' => $row['name_kana'],
            'type' => $row['oshi_type'],
            'categories' => [$typeLabels[$row['oshi_type']] ?? 'その他'],
            'icon_key' => $row['default_icon_key'],
            'color' => $row['default_color'],
            'is_following' => (bool) $row['is_following'],
            'follower_count' => (int) $row['follower_count'],
        ],
        $rows
    );

    jsonResponse(
        [
            'data' => $data,
            'meta' => ['next_cursor' => null],
        ]
    );
}

if ($method === 'GET' && preg_match('#^/api/oshis/(\d+)$#', $path, $matches) === 1) {
    $oshiId = (int) $matches[1];
    $viewerUserId = currentUserId() ?? 0;
    $statement = database()->prepare(
        "SELECT o.id, o.name, o.short_name, o.name_kana, o.alternate_names, o.oshi_type,
                o.description, o.default_icon_key, o.default_color,
                COALESCE(uo.custom_icon_key, o.default_icon_key) AS icon_key,
                COALESCE(uo.custom_color, o.default_color) AS color,
                CASE WHEN uo.custom_icon_key IS NOT NULL OR uo.custom_color IS NOT NULL THEN 1 ELSE 0 END AS has_custom_appearance,
                CASE WHEN uo.is_active = 1 THEN 1 ELSE 0 END AS is_following,
                (SELECT COUNT(*) FROM user_oshis followers WHERE followers.oshi_id = o.id AND followers.is_active = 1) AS follower_count
         FROM oshis o
         LEFT JOIN user_oshis uo ON uo.oshi_id = o.id AND uo.user_id = :viewer_user_id
         WHERE o.id = :oshi_id AND o.status = 'active'"
    );
    $statement->execute(['viewer_user_id' => $viewerUserId, 'oshi_id' => $oshiId]);
    $row = $statement->fetch();
    if ($row === false) errorResponse('NOT_FOUND', '指定された推しが見つかりません。', 404);
    $linkStatement = database()->prepare(
        "SELECT id, link_type, label, url FROM oshi_links
         WHERE oshi_id = :oshi_id AND status = 'active' ORDER BY sort_order, id"
    );
    $linkStatement->execute(['oshi_id' => $oshiId]);
    $typeLabels = ['person' => '人物', 'group' => 'グループ', 'work' => '作品', 'character' => 'キャラクター', 'other' => 'その他'];
    jsonResponse(['data' => [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'short_name' => $row['short_name'],
        'name_kana' => $row['name_kana'],
        'alternate_names' => json_decode((string) ($row['alternate_names'] ?? '[]'), true) ?: [],
        'type' => $row['oshi_type'],
        'type_label' => $typeLabels[$row['oshi_type']] ?? 'その他',
        'description' => $row['description'],
        'icon_key' => $row['icon_key'],
        'color' => $row['color'],
        'has_custom_appearance' => (bool) $row['has_custom_appearance'],
        'is_following' => (bool) $row['is_following'],
        'follower_count' => (int) $row['follower_count'],
        'links' => array_map(static fn (array $link): array => [
            'id' => (int) $link['id'], 'type' => $link['link_type'], 'label' => $link['label'], 'url' => $link['url'],
        ], $linkStatement->fetchAll()),
    ]]);
}

if (preg_match('#^/api/me/oshis/(\d+)$#', $path, $matches) === 1 && in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
    $userId = requireAuthenticatedUser();
    requireCsrfToken();
    $oshiId = (int) $matches[1];

    $oshiStatement = database()->prepare("SELECT id FROM oshis WHERE id = :id AND status = 'active'");
    $oshiStatement->execute(['id' => $oshiId]);
    if ($oshiStatement->fetch() === false) {
        errorResponse('NOT_FOUND', '指定された推しが見つかりません。', 404);
    }

    if ($method === 'PUT') {
        $statement = database()->prepare(
            'INSERT INTO user_oshis (user_id, oshi_id, is_active)
             VALUES (:user_id, :oshi_id, 1)
             ON DUPLICATE KEY UPDATE is_active = 1, updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute(['user_id' => $userId, 'oshi_id' => $oshiId]);
        jsonResponse(['data' => ['oshi_id' => $oshiId, 'is_following' => true]]);
    }

    if ($method === 'PATCH') {
        $body = jsonRequestBody();
        $reset = ($body['reset'] ?? false) === true;
        $iconKey = $reset ? null : (string) ($body['icon_key'] ?? '');
        $color = $reset ? null : strtoupper((string) ($body['color'] ?? ''));
        if (!$reset && (!in_array($iconKey, ['microphone', 'music', 'play'], true) || preg_match('/\A#[0-9A-F]{6}\z/', $color) !== 1)) {
            errorResponse('VALIDATION_ERROR', 'アイコンと色を確認してください。', 422);
        }
        $statement = database()->prepare(
            'UPDATE user_oshis
             SET custom_icon_key = :icon_key, custom_color = :color, updated_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id AND oshi_id = :oshi_id AND is_active = 1'
        );
        $statement->execute(['icon_key' => $iconKey, 'color' => $color, 'user_id' => $userId, 'oshi_id' => $oshiId]);
        if ($statement->rowCount() === 0) {
            $followStatement = database()->prepare('SELECT id FROM user_oshis WHERE user_id = :user_id AND oshi_id = :oshi_id AND is_active = 1');
            $followStatement->execute(['user_id' => $userId, 'oshi_id' => $oshiId]);
            if ($followStatement->fetch() === false) errorResponse('FORBIDDEN', '推し登録してから変更してください。', 403);
        }
        $appearanceStatement = database()->prepare(
            'SELECT COALESCE(uo.custom_icon_key, o.default_icon_key) AS icon_key,
                    COALESCE(uo.custom_color, o.default_color) AS color
             FROM user_oshis uo INNER JOIN oshis o ON o.id = uo.oshi_id
             WHERE uo.user_id = :user_id AND uo.oshi_id = :oshi_id'
        );
        $appearanceStatement->execute(['user_id' => $userId, 'oshi_id' => $oshiId]);
        $appearance = $appearanceStatement->fetch();
        jsonResponse(['data' => ['oshi_id' => $oshiId, 'icon_key' => $appearance['icon_key'], 'color' => $appearance['color'], 'has_custom_appearance' => !$reset]]);
    }

    $statement = database()->prepare(
        'UPDATE user_oshis SET is_active = 0, updated_at = CURRENT_TIMESTAMP
         WHERE user_id = :user_id AND oshi_id = :oshi_id'
    );
    $statement->execute(['user_id' => $userId, 'oshi_id' => $oshiId]);
    http_response_code(204);
    exit;
}

if ($method === 'GET' && $path === '/api/me/oshis') {
    $userId = requireAuthenticatedUser();
    $statement = database()->prepare(
        "SELECT
            o.id,
            o.name,
            o.short_name,
            o.oshi_type,
            COALESCE(uo.custom_icon_key, o.default_icon_key) AS icon_key,
            COALESCE(uo.custom_color, o.default_color) AS color
         FROM user_oshis uo
         INNER JOIN oshis o ON o.id = uo.oshi_id AND o.status = 'active'
         WHERE uo.user_id = :user_id AND uo.is_active = 1
         ORDER BY uo.updated_at DESC, o.id DESC"
    );
    $statement->execute(['user_id' => $userId]);
    $data = array_map(
        static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'short_name' => $row['short_name'],
            'type' => $row['oshi_type'],
            'icon_key' => $row['icon_key'],
            'color' => $row['color'],
        ],
        $statement->fetchAll()
    );
    jsonResponse(['data' => $data]);
}

if ($method === 'POST' && $path === '/api/url-previews') {
    $userId = requireAuthenticatedUser();
    requireCsrfToken();
    $url = trim((string) (jsonRequestBody()['url'] ?? ''));
    $countStatement = database()->prepare(
        'SELECT COUNT(*) FROM url_previews WHERE user_id = :user_id AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR'
    );
    $countStatement->execute(['user_id' => $userId]);
    if ((int) $countStatement->fetchColumn() >= 20) {
        errorResponse('PREVIEW_RATE_LIMITED', 'URLの確認回数が上限に達しました。しばらく待ってからお試しください。', 429);
    }
    try {
        $preview = fetchSafeUrlPreview($url);
    } catch (Throwable $exception) {
        error_log('URL preview failed: ' . $exception->getMessage());
        errorResponse('PREVIEW_FAILED', 'ページ情報を取得できませんでした。URLを保存してカード作成は続けられます。', 422);
    }
    $token = bin2hex(random_bytes(32));
    $pdo = database();
    $pdo->prepare('DELETE FROM url_previews WHERE expires_at < UTC_TIMESTAMP()')->execute();
    $statement = $pdo->prepare(
        'INSERT INTO url_previews
            (token, user_id, submitted_url, resolved_url, page_title, image_mime, image_data, expires_at)
         VALUES (:token, :user_id, :submitted_url, :resolved_url, :page_title, :image_mime, :image_data,
                 UTC_TIMESTAMP() + INTERVAL 30 MINUTE)'
    );
    $statement->bindValue(':token', $token);
    $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $statement->bindValue(':submitted_url', $url);
    $statement->bindValue(':resolved_url', $preview['resolved_url']);
    $statement->bindValue(':page_title', $preview['page_title']);
    $statement->bindValue(':image_mime', $preview['image_mime']);
    $statement->bindValue(':image_data', $preview['image_data'], $preview['image_data'] === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $statement->execute();
    $domain = parse_url($preview['resolved_url'], PHP_URL_HOST);
    jsonResponse(['data' => [
        'token' => $token,
        'page_title' => $preview['page_title'],
        'resolved_url' => $preview['resolved_url'],
        'domain' => is_string($domain) ? $domain : '',
        'thumbnail_url' => $preview['image_data'] === null ? null : "/api/url-previews/$token/image",
    ]], 201);
}

if ($method === 'GET' && preg_match('#^/api/url-previews/([a-f0-9]{64})/image$#', $path, $matches) === 1) {
    $userId = requireAuthenticatedUser();
    $statement = database()->prepare(
        'SELECT image_mime, image_data FROM url_previews
         WHERE token = :token AND user_id = :user_id AND expires_at >= UTC_TIMESTAMP()'
    );
    $statement->execute(['token' => $matches[1], 'user_id' => $userId]);
    $image = $statement->fetch();
    if ($image === false || $image['image_data'] === null) errorResponse('NOT_FOUND', '画像が見つかりません。', 404);
    header('Content-Type: ' . $image['image_mime']);
    header('Cache-Control: private, max-age=300');
    echo $image['image_data'];
    exit;
}

if ($method === 'GET' && preg_match('#^/api/source-images/(\d+)$#', $path, $matches) === 1) {
    $statement = database()->prepare('SELECT mime_type, image_data, sha256 FROM source_images WHERE source_id = :source_id');
    $statement->execute(['source_id' => (int) $matches[1]]);
    $image = $statement->fetch();
    if ($image === false) errorResponse('NOT_FOUND', '画像が見つかりません。', 404);
    header('Content-Type: ' . $image['mime_type']);
    header('Cache-Control: public, max-age=86400');
    header('ETag: "' . $image['sha256'] . '"');
    echo $image['image_data'];
    exit;
}

if (preg_match('#^/api/occurrences/(\d+)/setlist$#', $path, $matches) === 1 && in_array($method, ['GET', 'PUT'], true)) {
    $occurrenceId = (int) $matches[1];
    $pdo = database();
    $occurrenceStatement = $pdo->prepare(
        "SELECT oc.id FROM occurrences oc
         INNER JOIN cards c ON c.id = oc.card_id
         WHERE oc.id = :id AND c.status = 'active'"
    );
    $occurrenceStatement->execute(['id' => $occurrenceId]);
    if ($occurrenceStatement->fetch() === false) {
        errorResponse('NOT_FOUND', '指定された公演が見つかりません。', 404);
    }

    $setlistStatement = $pdo->prepare('SELECT id, created_by FROM setlists WHERE occurrence_id = :occurrence_id');
    $setlistStatement->execute(['occurrence_id' => $occurrenceId]);
    $setlistRow = $setlistStatement->fetch();

    if ($method === 'GET') {
        $viewerUserId = currentUserId();
        if ($setlistRow === false) {
            jsonResponse(['data' => ['setlist' => null, 'can_create' => $viewerUserId !== null]]);
        }
        $itemStatement = $pdo->prepare(
            'SELECT id, item_type, title FROM setlist_items WHERE setlist_id = :setlist_id ORDER BY sort_order, id'
        );
        $itemStatement->execute(['setlist_id' => $setlistRow['id']]);
        $songNumber = 0;
        $items = array_map(static function (array $row) use (&$songNumber): array {
            $number = null;
            if ($row['item_type'] === 'song') $number = ++$songNumber;
            return ['id' => (int) $row['id'], 'type' => $row['item_type'], 'title' => $row['title'], 'song_number' => $number];
        }, $itemStatement->fetchAll());
        jsonResponse(['data' => ['setlist' => [
            'id' => (int) $setlistRow['id'],
            'occurrence_id' => $occurrenceId,
            'items' => $items,
            'can_edit' => $viewerUserId !== null && (int) $setlistRow['created_by'] === $viewerUserId,
        ], 'can_create' => false]]);
    }

    $userId = requireAuthenticatedUser();
    requireCsrfToken();
    if ($setlistRow !== false && (int) $setlistRow['created_by'] !== $userId) {
        errorResponse('FORBIDDEN', 'ほかのユーザーが登録したセットリストの編集機能は準備中です。', 403);
    }
    $body = jsonRequestBody();
    $itemInputs = $body['items'] ?? null;
    if (!is_array($itemInputs) || $itemInputs === [] || count($itemInputs) > 200) {
        errorResponse('VALIDATION_ERROR', 'セットリストは1〜200行で入力してください。', 422);
    }
    $allowedItemTypes = ['song', 'mc', 'op', 'se', 'ed', 'other'];
    $items = [];
    foreach ($itemInputs as $index => $input) {
        $type = is_array($input) ? (string) ($input['type'] ?? '') : '';
        $title = is_array($input) ? trim((string) ($input['title'] ?? '')) : '';
        if (!in_array($type, $allowedItemTypes, true) || preg_match('/\A.{1,300}\z/us', $title) !== 1) {
            errorResponse('VALIDATION_ERROR', ($index + 1) . '行目の種類または内容を確認してください。', 422);
        }
        $items[] = ['type' => $type, 'title' => $title];
    }

    try {
        $pdo->beginTransaction();
        if ($setlistRow === false) {
            $statement = $pdo->prepare(
                'INSERT INTO setlists (occurrence_id, created_by, updated_by) VALUES (:occurrence_id, :created_by, :updated_by)'
            );
            $statement->execute(['occurrence_id' => $occurrenceId, 'created_by' => $userId, 'updated_by' => $userId]);
            $setlistId = (int) $pdo->lastInsertId();
        } else {
            $setlistId = (int) $setlistRow['id'];
            $pdo->prepare('UPDATE setlists SET updated_by = :user_id WHERE id = :id')->execute(['user_id' => $userId, 'id' => $setlistId]);
            $pdo->prepare('DELETE FROM setlist_items WHERE setlist_id = :setlist_id')->execute(['setlist_id' => $setlistId]);
        }
        $itemStatement = $pdo->prepare(
            'INSERT INTO setlist_items (setlist_id, item_type, title, sort_order) VALUES (:setlist_id, :item_type, :title, :sort_order)'
        );
        foreach ($items as $index => $item) {
            $itemStatement->execute(['setlist_id' => $setlistId, 'item_type' => $item['type'], 'title' => $item['title'], 'sort_order' => $index + 1]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($exception->getMessage());
        errorResponse('INTERNAL_ERROR', 'セットリストを保存できませんでした。', 500);
    }
    $songNumber = 0;
    jsonResponse(['data' => ['setlist' => [
        'id' => $setlistId,
        'occurrence_id' => $occurrenceId,
        'items' => array_map(static function (array $item, int $index) use (&$songNumber): array {
            return ['id' => $index + 1, 'type' => $item['type'], 'title' => $item['title'], 'song_number' => $item['type'] === 'song' ? ++$songNumber : null];
        }, $items, array_keys($items)),
        'can_edit' => true,
    ]]], $setlistRow === false ? 201 : 200);
}

if ($method === 'POST' && $path === '/api/cards') {
    $userId = requireAuthenticatedUser();
    requireCsrfToken();
    $body = jsonRequestBody();
    $cardType = (string) ($body['type'] ?? '');
    $oshiId = filter_var($body['oshi_id'] ?? null, FILTER_VALIDATE_INT);
    $title = trim((string) ($body['title'] ?? ''));
    $summary = trim((string) ($body['summary'] ?? ''));
    $occurrenceInputs = $body['occurrences'] ?? [[
        'label' => '',
        'starts_at' => $body['starts_at'] ?? null,
        'venue_name' => $body['venue_name'] ?? '',
    ]];
    $sourceUrl = trim((string) ($body['source_url'] ?? ''));
    $previewToken = trim((string) ($body['preview_token'] ?? ''));
    $useThumbnail = ($body['use_thumbnail'] ?? true) === true;
    $allowedTypes = ['concert', 'tour', 'event', 'ticket', 'goods', 'release', 'streaming', 'media', 'movie', 'other'];
    $fields = [];

    if (!in_array($cardType, $allowedTypes, true)) {
        $fields['type'] = 'カード種類を選択してください。';
    }
    if ($oshiId === false) {
        $fields['oshi_id'] = '対象の推しを選択してください。';
    }
    if (preg_match('/\A[^\r\n]{1,200}\z/u', $title) !== 1) {
        $fields['title'] = 'タイトルは1〜200文字で入力してください。';
    }
    if (strlen($summary) > 15000) {
        $fields['summary'] = '内容が長すぎます。';
    }
    if (!is_array($occurrenceInputs) || $occurrenceInputs === [] || count($occurrenceInputs) > 100) {
        $fields['occurrences'] = '日程は1〜100件で入力してください。';
        $occurrenceInputs = [];
    }

    $occurrences = [];
    foreach ($occurrenceInputs as $index => $occurrenceInput) {
        if (!is_array($occurrenceInput)) {
            $fields["occurrences.$index"] = '日程を確認してください。';
            continue;
        }
        $label = trim((string) ($occurrenceInput['label'] ?? ''));
        $startsAtInput = trim((string) ($occurrenceInput['starts_at'] ?? ''));
        $venueName = trim((string) ($occurrenceInput['venue_name'] ?? ''));
        if (preg_match('/\A.{0,150}\z/us', $label) !== 1) {
            $fields["occurrences.$index.label"] = '公演名は150文字以内で入力してください。';
        }
        if (preg_match('/\A.{0,200}\z/us', $venueName) !== 1) {
            $fields["occurrences.$index.venue_name"] = '会場名は200文字以内で入力してください。';
        }
        $startsAt = null;
        if ($startsAtInput !== '') {
            try {
                $startsAt = (new DateTimeImmutable($startsAtInput))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s');
            } catch (Throwable) {
                $fields["occurrences.$index.starts_at"] = '日時を確認してください。';
            }
        }
        $occurrences[] = ['label' => $label, 'starts_at' => $startsAt, 'venue_name' => $venueName];
    }

    if ($sourceUrl !== '') {
        $parts = parse_url($sourceUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (strlen($sourceUrl) > 2048 || !in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            $fields['source_url'] = 'httpまたはhttpsのURLを入力してください。';
        }
    }

    if ($fields !== []) {
        jsonResponse([
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => '入力内容を確認してください。',
                'fields' => $fields,
            ],
        ], 422);
    }

    $pdo = database();
    try {
        $oshiStatement = $pdo->prepare(
            "SELECT o.id
             FROM oshis o
             INNER JOIN user_oshis uo ON uo.oshi_id = o.id
             WHERE o.id = :oshi_id AND o.status = 'active'
               AND uo.user_id = :user_id AND uo.is_active = 1"
        );
        $oshiStatement->execute(['oshi_id' => $oshiId, 'user_id' => $userId]);
        if ($oshiStatement->fetch() === false) {
            errorResponse('FORBIDDEN', '登録中の推しを選択してください。', 403);
        }

        $pdo->beginTransaction();
        $cardStatement = $pdo->prepare(
            "INSERT INTO cards (
                card_type, title, summary, benefit_status, schedule_status,
                announced_period, placeholder_key, created_by, status
             ) VALUES (
                :card_type, :title, :summary, 'unknown', :schedule_status,
                NULL, :placeholder_key, :created_by, 'active'
             )"
        );
        $cardStatement->execute([
            'card_type' => $cardType,
            'title' => $title,
            'summary' => $summary === '' ? null : $summary,
            'schedule_status' => count(array_filter($occurrences, static fn (array $occurrence): bool => $occurrence['starts_at'] !== null)) > 0 ? 'scheduled' : 'undated',
            'placeholder_key' => $cardType,
            'created_by' => $userId,
        ]);
        $cardId = (int) $pdo->lastInsertId();

        $relationStatement = $pdo->prepare(
            "INSERT INTO card_oshis (card_id, oshi_id, relation_type)
             VALUES (:card_id, :oshi_id, 'primary')"
        );
        $relationStatement->execute(['card_id' => $cardId, 'oshi_id' => $oshiId]);

        $occurrenceStatement = $pdo->prepare(
            "INSERT INTO occurrences (
                card_id, label, starts_at, ends_at, date_precision,
                venue_name, venue_address, status, sort_order
             ) VALUES (
                :card_id, :label, :starts_at, NULL, :date_precision,
                :venue_name, NULL, 'scheduled', :sort_order
             )"
        );
        $occurrenceIds = [];
        foreach ($occurrences as $index => $occurrence) {
            $occurrenceStatement->execute([
                'card_id' => $cardId,
                'label' => $occurrence['label'] === '' ? null : $occurrence['label'],
                'starts_at' => $occurrence['starts_at'],
                'date_precision' => $occurrence['starts_at'] === null ? 'unknown' : 'datetime',
                'venue_name' => $occurrence['venue_name'] === '' ? null : $occurrence['venue_name'],
                'sort_order' => $index + 1,
            ]);
            $occurrenceIds[] = (int) $pdo->lastInsertId();
        }

        $sourceId = null;
        if ($sourceUrl !== '') {
            $preview = null;
            if (preg_match('/\A[a-f0-9]{64}\z/', $previewToken) === 1) {
                $previewStatement = $pdo->prepare(
                    'SELECT token, resolved_url, page_title, image_mime, image_data
                     FROM url_previews
                     WHERE token = :token AND user_id = :user_id AND submitted_url = :submitted_url
                       AND expires_at >= UTC_TIMESTAMP() FOR UPDATE'
                );
                $previewStatement->execute(['token' => $previewToken, 'user_id' => $userId, 'submitted_url' => $sourceUrl]);
                $preview = $previewStatement->fetch();
                if ($preview === false) $preview = null;
            }
            $sourceStatement = $pdo->prepare(
                "INSERT INTO card_sources (
                    card_id, source_role, submitted_url, resolved_url,
                    canonical_url, outbound_url, page_title, thumbnail_url,
                    fetch_status, created_by
                 ) VALUES (
                    :card_id, 'primary', :submitted_url, :resolved_url,
                    NULL, :outbound_url, :page_title, NULL,
                    :fetch_status, :created_by
                 )"
            );
            $sourceStatement->execute([
                'card_id' => $cardId,
                'submitted_url' => $sourceUrl,
                'resolved_url' => $preview['resolved_url'] ?? null,
                'outbound_url' => $preview['resolved_url'] ?? $sourceUrl,
                'page_title' => $preview['page_title'] ?? null,
                'fetch_status' => $preview === null ? 'pending' : 'success',
                'created_by' => $userId,
            ]);
            $sourceId = (int) $pdo->lastInsertId();
            if ($preview !== null && $useThumbnail && $preview['image_data'] !== null) {
                $imageStatement = $pdo->prepare(
                    'INSERT INTO source_images (source_id, mime_type, image_data, sha256)
                     VALUES (:source_id, :mime_type, :image_data, :sha256)'
                );
                $imageStatement->bindValue(':source_id', $sourceId, PDO::PARAM_INT);
                $imageStatement->bindValue(':mime_type', $preview['image_mime']);
                $imageStatement->bindValue(':image_data', $preview['image_data'], PDO::PARAM_LOB);
                $imageStatement->bindValue(':sha256', hash('sha256', $preview['image_data']));
                $imageStatement->execute();
                $pdo->prepare('UPDATE card_sources SET thumbnail_url = :thumbnail_url WHERE id = :id')->execute([
                    'thumbnail_url' => "/api/source-images/$sourceId",
                    'id' => $sourceId,
                ]);
            }
            if ($preview !== null) {
                $pdo->prepare('DELETE FROM url_previews WHERE token = :token')->execute(['token' => $previewToken]);
            }
        }

        notifyOshiFollowers($pdo, $oshiId, $userId, $cardId, $title);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        errorResponse('INTERNAL_ERROR', 'カードを追加できませんでした。', 500);
    }

    jsonResponse([
        'data' => [
            'id' => $cardId,
            'occurrence_id' => $occurrenceIds[0],
            'occurrence_ids' => $occurrenceIds,
            'source_id' => $sourceId,
        ],
    ], 201);
}

if ($method === 'PATCH' && preg_match('#^/api/cards/(\d+)$#', $path, $matches) === 1) {
    $userId = requireAuthenticatedUser();
    requireCsrfToken();
    $cardId = (int) $matches[1];
    $body = jsonRequestBody();
    $title = trim((string) ($body['title'] ?? ''));
    $summary = trim((string) ($body['summary'] ?? ''));
    $fields = [];
    if (preg_match('/\A[^\r\n]{1,200}\z/u', $title) !== 1) {
        $fields['title'] = 'タイトルは1〜200文字で入力してください。';
    }
    if (strlen($summary) > 15000) {
        $fields['summary'] = '内容が長すぎます。';
    }
    if ($fields !== []) {
        jsonResponse(['error' => ['code' => 'VALIDATION_ERROR', 'message' => '入力内容を確認してください。', 'fields' => $fields]], 422);
    }

    $pdo = database();
    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            "SELECT title, summary, created_by FROM cards
             WHERE id = :card_id AND status = 'active' FOR UPDATE"
        );
        $statement->execute(['card_id' => $cardId]);
        $current = $statement->fetch();
        if ($current === false) {
            $pdo->rollBack();
            errorResponse('NOT_FOUND', '指定されたカードが見つかりません。', 404);
        }
        $before = ['title' => $current['title'], 'summary' => $current['summary']];
        $after = ['title' => $title, 'summary' => $summary === '' ? null : $summary];
        if ($before === $after) {
            $pdo->rollBack();
            errorResponse('NO_CHANGES', '変更された内容がありません。', 422);
        }

        if ((int) $current['created_by'] !== $userId) {
            $userStatement = $pdo->prepare(
                'SELECT email_verified_at, created_at, role FROM users WHERE id = :user_id FOR UPDATE'
            );
            $userStatement->execute(['user_id' => $userId]);
            $editingUser = $userStatement->fetch();
            if ($editingUser === false || $editingUser['email_verified_at'] === null) {
                $pdo->rollBack();
                errorResponse('EMAIL_VERIFICATION_REQUIRED', '編集案の提出にはメール認証が必要です。', 403);
            }

            $accountAge = time() - (strtotime($editingUser['created_at'] . ' UTC') ?: time());
            $dailyLimit = $accountAge < 7 * 86400 ? 3 : 10;
            if ($editingUser['role'] === 'admin') {
                $dailyLimit = 100;
            }
            $countStatement = $pdo->prepare(
                'SELECT COUNT(*) FROM card_edit_proposals
                 WHERE proposed_by = :user_id AND created_at >= UTC_DATE()'
            );
            $countStatement->execute(['user_id' => $userId]);
            if ((int) $countStatement->fetchColumn() >= $dailyLimit) {
                $pdo->rollBack();
                errorResponse('DAILY_EDIT_LIMIT_REACHED', "本日の編集案は上限{$dailyLimit}件に達しました。", 429);
            }

            $proposalStatement = $pdo->prepare(
                "INSERT INTO card_edit_proposals
                    (card_id, proposed_by, before_data, proposed_data, status)
                 VALUES (:card_id, :proposed_by, :before_data, :proposed_data, 'pending')"
            );
            $proposalStatement->execute([
                'card_id' => $cardId,
                'proposed_by' => $userId,
                'before_data' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'proposed_data' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $proposalId = (int) $pdo->lastInsertId();
            $pdo->commit();
            jsonResponse(['data' => ['id' => $cardId, 'mode' => 'proposal', 'proposal_id' => $proposalId]], 202);
        }

        if ($before !== $after) {
            $revisionStatement = $pdo->prepare(
                "INSERT INTO card_revisions (card_id, changed_by, change_kind, before_data, after_data)
                 VALUES (:card_id, :changed_by, 'owner_update', :before_data, :after_data)"
            );
            $revisionStatement->execute([
                'card_id' => $cardId,
                'changed_by' => $userId,
                'before_data' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'after_data' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $updateStatement = $pdo->prepare(
                'UPDATE cards SET title = :title, summary = :summary WHERE id = :card_id'
            );
            $updateStatement->execute(['title' => $title, 'summary' => $after['summary'], 'card_id' => $cardId]);
            notifyCardScheduleUsers($pdo, $cardId, $userId, $title);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        errorResponse('INTERNAL_ERROR', 'カードを更新できませんでした。', 500);
    }

    jsonResponse(['data' => ['id' => $cardId, 'mode' => 'updated']]);
}

if ($method === 'GET' && preg_match('#^/api/cards/(\d+)/edit-proposals$#', $path, $matches) === 1) {
    $userId = requireAuthenticatedUser();
    try {
        $proposals = pendingCardEditProposals(database(), (int) $matches[1], $userId);
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        errorResponse('INTERNAL_ERROR', '編集案を取得できませんでした。', 500);
    }
    jsonResponse(['data' => $proposals]);
}

if ($method === 'POST' && preg_match('#^/api/edit-proposals/(\d+)/votes$#', $path, $matches) === 1) {
    $userId = requireAuthenticatedUser();
    requireCsrfToken();
    $proposalId = (int) $matches[1];
    $decision = jsonRequestBody()['decision'] ?? null;
    if (!in_array($decision, ['proposal', 'current'], true)) {
        errorResponse('VALIDATION_ERROR', '確認結果を選択してください。', 422);
    }

    $pdo = database();
    try {
        $pdo->beginTransaction();
        $proposalStatement = $pdo->prepare(
            "SELECT id, card_id, proposed_by, before_data, proposed_data, status
             FROM card_edit_proposals WHERE id = :proposal_id FOR UPDATE"
        );
        $proposalStatement->execute(['proposal_id' => $proposalId]);
        $proposal = $proposalStatement->fetch();
        if ($proposal === false) {
            $pdo->rollBack();
            errorResponse('NOT_FOUND', '編集案が見つかりません。', 404);
        }
        if ($proposal['status'] !== 'pending') {
            $pdo->rollBack();
            errorResponse('PROPOSAL_ALREADY_DECIDED', 'この編集案の確認は終了しています。', 409);
        }
        if ((int) $proposal['proposed_by'] === $userId) {
            $pdo->rollBack();
            errorResponse('OWN_PROPOSAL_VOTE_FORBIDDEN', '自分の編集案には投票できません。', 403);
        }

        $voterStatement = $pdo->prepare(
            'SELECT email_verified_at, created_at FROM users WHERE id = :user_id'
        );
        $voterStatement->execute(['user_id' => $userId]);
        $voter = $voterStatement->fetch();
        $createdAt = $voter === false ? false : strtotime($voter['created_at'] . ' UTC');
        if ($voter === false || $voter['email_verified_at'] === null || $createdAt === false || $createdAt > time() - 7 * 86400) {
            $pdo->rollBack();
            errorResponse('VOTER_NOT_ELIGIBLE', '編集案の確認は、メール認証済みかつ登録7日以上のユーザーが行えます。', 403);
        }

        $existingVoteStatement = $pdo->prepare(
            'SELECT id FROM card_edit_votes WHERE proposal_id = :proposal_id AND voter_id = :voter_id'
        );
        $existingVoteStatement->execute(['proposal_id' => $proposalId, 'voter_id' => $userId]);
        if ($existingVoteStatement->fetch() !== false) {
            $pdo->rollBack();
            errorResponse('ALREADY_VOTED', 'この編集案はすでに確認済みです。', 409);
        }

        $voteStatement = $pdo->prepare(
            'INSERT INTO card_edit_votes (proposal_id, voter_id, decision)
             VALUES (:proposal_id, :voter_id, :decision)'
        );
        $voteStatement->execute(['proposal_id' => $proposalId, 'voter_id' => $userId, 'decision' => $decision]);
        $countStatement = $pdo->prepare(
            "SELECT
                SUM(decision = 'proposal') AS proposal_votes,
                SUM(decision = 'current') AS current_votes
             FROM card_edit_votes WHERE proposal_id = :proposal_id"
        );
        $countStatement->execute(['proposal_id' => $proposalId]);
        $counts = $countStatement->fetch();
        $proposalVotes = (int) $counts['proposal_votes'];
        $currentVotes = (int) $counts['current_votes'];
        $status = 'pending';

        if ($proposalVotes >= 2) {
            $cardStatement = $pdo->prepare(
                "SELECT title, summary FROM cards
                 WHERE id = :card_id AND status = 'active' FOR UPDATE"
            );
            $cardStatement->execute(['card_id' => $proposal['card_id']]);
            $card = $cardStatement->fetch();
            $before = json_decode($proposal['before_data'], true, 512, JSON_THROW_ON_ERROR);
            $proposed = json_decode($proposal['proposed_data'], true, 512, JSON_THROW_ON_ERROR);
            if ($card === false || ['title' => $card['title'], 'summary' => $card['summary']] !== $before) {
                $status = 'stale';
            } else {
                $revisionStatement = $pdo->prepare(
                    "INSERT INTO card_revisions (card_id, changed_by, change_kind, before_data, after_data)
                     VALUES (:card_id, :changed_by, 'proposal_accepted', :before_data, :after_data)"
                );
                $revisionStatement->execute([
                    'card_id' => $proposal['card_id'],
                    'changed_by' => $userId,
                    'before_data' => $proposal['before_data'],
                    'after_data' => $proposal['proposed_data'],
                ]);
                $updateCardStatement = $pdo->prepare(
                    'UPDATE cards SET title = :title, summary = :summary WHERE id = :card_id'
                );
                $updateCardStatement->execute([
                    'title' => $proposed['title'],
                    'summary' => $proposed['summary'],
                    'card_id' => $proposal['card_id'],
                ]);
                $status = 'accepted';
            }
        } elseif ($currentVotes >= 2) {
            $status = 'rejected';
        }

        $updateProposalStatement = $pdo->prepare(
            'UPDATE card_edit_proposals
             SET correct_votes = :proposal_votes, current_votes = :current_votes,
                 status = :status, decided_at = CASE WHEN :is_decided = 1 THEN UTC_TIMESTAMP() ELSE NULL END
             WHERE id = :proposal_id'
        );
        $updateProposalStatement->execute([
            'proposal_votes' => $proposalVotes,
            'current_votes' => $currentVotes,
            'status' => $status,
            'is_decided' => $status === 'pending' ? 0 : 1,
            'proposal_id' => $proposalId,
        ]);
        if ($status !== 'pending') {
            $notificationTitle = match ($status) {
                'accepted' => '編集案が採用されました',
                'rejected' => '現在の内容を残すことが決まりました',
                default => '編集案の確認が終了しました',
            };
            createNotification(
                $pdo,
                (int) $proposal['proposed_by'],
                "edit_proposal_$status",
                (int) $proposal['card_id'],
                $notificationTitle,
                $status === 'accepted' ? $proposed['title'] : '対象のカードを確認してください。',
                "edit_proposal:$proposalId"
            );
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        errorResponse('INTERNAL_ERROR', '編集案を確認できませんでした。', 500);
    }

    jsonResponse(['data' => [
        'id' => $proposalId,
        'status' => $status,
        'proposal_votes' => $proposalVotes,
        'current_votes' => $currentVotes,
    ]]);
}

if ($method === 'POST' && $path === '/api/reports') {
    $userId = requireAuthenticatedUser();
    requireCsrfToken();
    $body = jsonRequestBody();
    $targetType = (string) ($body['target_type'] ?? '');
    $targetId = filter_var($body['target_id'] ?? null, FILTER_VALIDATE_INT);
    $reason = (string) ($body['reason'] ?? '');
    $details = trim((string) ($body['details'] ?? ''));
    $allowedReasons = ['incorrect', 'duplicate', 'inappropriate', 'unsafe_url', 'rights', 'other'];
    $fields = [];
    if ($targetType !== 'card') {
        $fields['target_type'] = '現在通報できる対象ではありません。';
    }
    if ($targetId === false) {
        $fields['target_id'] = '通報対象を確認してください。';
    }
    if (!in_array($reason, $allowedReasons, true)) {
        $fields['reason'] = '通報理由を選択してください。';
    }
    if (preg_match('/\A.{0,2000}\z/us', $details) !== 1) {
        $fields['details'] = '補足は2000文字以内で入力してください。';
    }
    if ($fields !== []) {
        jsonResponse(['error' => ['code' => 'VALIDATION_ERROR', 'message' => '入力内容を確認してください。', 'fields' => $fields]], 422);
    }

    $cardStatement = database()->prepare("SELECT id FROM cards WHERE id = :id AND status = 'active'");
    $cardStatement->execute(['id' => $targetId]);
    if ($cardStatement->fetch() === false) {
        errorResponse('NOT_FOUND', '通報対象のカードが見つかりません。', 404);
    }
    $dailyStatement = database()->prepare(
        'SELECT COUNT(*) FROM reports WHERE reporter_id = :reporter_id AND created_at >= UTC_DATE()'
    );
    $dailyStatement->execute(['reporter_id' => $userId]);
    if ((int) $dailyStatement->fetchColumn() >= 20) {
        errorResponse('DAILY_REPORT_LIMIT_REACHED', '本日の通報上限に達しました。', 429);
    }

    try {
        $statement = database()->prepare(
            "INSERT INTO reports (reporter_id, target_type, target_id, reason, details, status)
             VALUES (:reporter_id, 'card', :target_id, :reason, :details, 'open')"
        );
        $statement->execute([
            'reporter_id' => $userId,
            'target_id' => $targetId,
            'reason' => $reason,
            'details' => $details === '' ? null : $details,
        ]);
        $reportId = (int) database()->lastInsertId();
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            errorResponse('ALREADY_REPORTED', 'このカードはすでに通報済みです。', 409);
        }
        error_log($exception->getMessage());
        errorResponse('INTERNAL_ERROR', '通報を送信できませんでした。', 500);
    }
    jsonResponse(['data' => ['id' => $reportId]], 201);
}

if ($method === 'GET' && $path === '/api/admin/reports') {
    requireStaffUser();
    $status = (string) ($_GET['status'] ?? 'open');
    if (!in_array($status, ['open', 'reviewing', 'resolved', 'dismissed'], true)) {
        errorResponse('VALIDATION_ERROR', '対応状態を確認してください。', 422);
    }
    $statement = database()->prepare(
        "SELECT r.id, r.target_type, r.target_id, r.reason, r.details, r.status,
                r.created_at, c.title AS card_title
         FROM reports r
         LEFT JOIN cards c ON r.target_type = 'card' AND c.id = r.target_id
         WHERE r.status = :status
         ORDER BY r.created_at, r.id LIMIT 200"
    );
    $statement->execute(['status' => $status]);
    $reports = array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'target_type' => $row['target_type'],
        'target_id' => (int) $row['target_id'],
        'target_title' => $row['card_title'],
        'reason' => $row['reason'],
        'details' => $row['details'],
        'status' => $row['status'],
        'created_at' => isoDateTime($row['created_at']),
    ], $statement->fetchAll());
    jsonResponse(['data' => $reports]);
}

if ($method === 'PATCH' && preg_match('#^/api/admin/reports/(\d+)$#', $path, $matches) === 1) {
    $staff = requireStaffUser();
    requireCsrfToken();
    $nextStatus = jsonRequestBody()['status'] ?? null;
    if (!in_array($nextStatus, ['reviewing', 'resolved', 'dismissed'], true)) {
        errorResponse('VALIDATION_ERROR', '対応状態を選択してください。', 422);
    }
    $reportId = (int) $matches[1];
    $pdo = database();
    try {
        $pdo->beginTransaction();
        $reportStatement = $pdo->prepare(
            'SELECT status FROM reports WHERE id = :id FOR UPDATE'
        );
        $reportStatement->execute(['id' => $reportId]);
        $report = $reportStatement->fetch();
        if ($report === false) {
            $pdo->rollBack();
            errorResponse('NOT_FOUND', '通報が見つかりません。', 404);
        }
        $beforeStatus = $report['status'];
        if ($beforeStatus !== $nextStatus) {
            $updateStatement = $pdo->prepare(
                'UPDATE reports SET status = :status, reviewed_by = :reviewed_by, reviewed_at = UTC_TIMESTAMP() WHERE id = :id'
            );
            $updateStatement->execute(['status' => $nextStatus, 'reviewed_by' => $staff['id'], 'id' => $reportId]);
            $auditStatement = $pdo->prepare(
                "INSERT INTO admin_actions (actor_id, action_type, target_type, target_id, before_data, after_data)
                 VALUES (:actor_id, 'report_status_changed', 'report', :target_id, :before_data, :after_data)"
            );
            $auditStatement->execute([
                'actor_id' => $staff['id'],
                'target_id' => $reportId,
                'before_data' => json_encode(['status' => $beforeStatus], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'after_data' => json_encode(['status' => $nextStatus], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($exception->getMessage());
        errorResponse('INTERNAL_ERROR', '通報の状態を更新できませんでした。', 500);
    }
    jsonResponse(['data' => ['id' => $reportId, 'status' => $nextStatus]]);
}

if ($method === 'GET' && preg_match('#^/api/cards/(\d+)$#', $path, $matches) === 1) {
    try {
        $card = cardDetail(database(), (int) $matches[1], currentUserId());
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        errorResponse('INTERNAL_ERROR', 'カード詳細を取得できませんでした。', 500);
    }

    if ($card === null) {
        errorResponse('NOT_FOUND', '指定されたカードが見つかりません。', 404);
    }
    jsonResponse(['data' => $card]);
}

if ($method === 'GET' && $path === '/api/cards') {
    $scope = (string) ($_GET['scope'] ?? 'following');
    $sort = (string) ($_GET['sort'] ?? 'new');
    $oshiId = isset($_GET['oshi_id']) ? filter_var($_GET['oshi_id'], FILTER_VALIDATE_INT) : null;

    if (!in_array($scope, ['following', 'all'], true)) {
        errorResponse('VALIDATION_ERROR', '現在使用できるカード範囲ではありません。', 422);
    }
    if (!in_array($sort, ['new', 'date', 'popular'], true)) {
        errorResponse('VALIDATION_ERROR', '指定された並び順は使用できません。', 422);
    }
    if ($oshiId === false || (is_int($oshiId) && $oshiId < 1)) {
        errorResponse('VALIDATION_ERROR', '指定された推しを確認してください。', 422);
    }

    $userId = $scope === 'following' ? requireAuthenticatedUser() : currentUserId();

    try {
        $cards = cardList(database(), $userId, $sort, $oshiId, $scope === 'following');
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        errorResponse('INTERNAL_ERROR', 'カード情報を取得できませんでした。', 500);
    }

    jsonResponse(['data' => $cards, 'meta' => ['next_cursor' => null]]);
}

if ($method === 'GET' && $path === '/api/me/schedules') {
    $state = (string) ($_GET['state'] ?? 'checked');
    $period = (string) ($_GET['period'] ?? 'future');

    if (!in_array($state, ['checked', 'committed'], true)) {
        errorResponse('VALIDATION_ERROR', '指定された予定の状態は使用できません。', 422);
    }
    if (!in_array($period, ['future', 'past', 'all'], true)) {
        errorResponse('VALIDATION_ERROR', '指定された期間は使用できません。', 422);
    }

    $userId = requireAuthenticatedUser();

    try {
        $cards = scheduleList(database(), $userId, $state, $period);
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        errorResponse('INTERNAL_ERROR', 'マイスケジュールを取得できませんでした。', 500);
    }

    jsonResponse(['data' => $cards, 'meta' => ['next_cursor' => null]]);
}

if ($method === 'GET' && $path === '/api/me/notifications') {
    $userId = requireAuthenticatedUser();
    $statement = database()->prepare(
        'SELECT id, notification_type, card_id, title, body, read_at, created_at
         FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC, id DESC LIMIT 100'
    );
    $statement->execute(['user_id' => $userId]);
    $notifications = array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'type' => $row['notification_type'],
        'card_id' => $row['card_id'] === null ? null : (int) $row['card_id'],
        'title' => $row['title'],
        'body' => $row['body'],
        'read' => $row['read_at'] !== null,
        'created_at' => isoDateTime($row['created_at']),
    ], $statement->fetchAll());
    $unreadStatement = database()->prepare(
        'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL'
    );
    $unreadStatement->execute(['user_id' => $userId]);
    jsonResponse(['data' => $notifications, 'meta' => ['unread_count' => (int) $unreadStatement->fetchColumn()]]);
}

if ($method === 'POST' && $path === '/api/me/notifications/read-all') {
    $userId = requireAuthenticatedUser();
    requireCsrfToken();
    $statement = database()->prepare(
        'UPDATE notifications SET read_at = COALESCE(read_at, UTC_TIMESTAMP()) WHERE user_id = :user_id'
    );
    $statement->execute(['user_id' => $userId]);
    http_response_code(204);
    exit;
}

if ($method === 'POST' && preg_match('#^/api/me/notifications/(\d+)/read$#', $path, $matches) === 1) {
    $userId = requireAuthenticatedUser();
    requireCsrfToken();
    $statement = database()->prepare(
        'UPDATE notifications SET read_at = COALESCE(read_at, UTC_TIMESTAMP())
         WHERE id = :id AND user_id = :user_id'
    );
    $statement->execute(['id' => (int) $matches[1], 'user_id' => $userId]);
    if ($statement->rowCount() === 0) {
        $existsStatement = database()->prepare('SELECT id FROM notifications WHERE id = :id AND user_id = :user_id');
        $existsStatement->execute(['id' => (int) $matches[1], 'user_id' => $userId]);
        if ($existsStatement->fetch() === false) {
            errorResponse('NOT_FOUND', 'お知らせが見つかりません。', 404);
        }
    }
    http_response_code(204);
    exit;
}

if (preg_match('#^/api/me/schedules/(\d+)$#', $path, $matches) === 1 && in_array($method, ['PUT', 'DELETE'], true)) {
    $userId = requireAuthenticatedUser();
    requireCsrfToken();
    $occurrenceId = (int) $matches[1];

    $occurrenceStatement = database()->prepare(
        'SELECT id FROM occurrences WHERE id = :id'
    );
    $occurrenceStatement->execute(['id' => $occurrenceId]);
    if ($occurrenceStatement->fetch() === false) {
        errorResponse('NOT_FOUND', '指定された開催枠が見つかりません。', 404);
    }

    if ($method === 'PUT') {
        $body = jsonRequestBody();
        $state = $body['state'] ?? null;
        if (!in_array($state, ['checked', 'committed'], true)) {
            errorResponse('VALIDATION_ERROR', 'チェックまたはコミットを指定してください。', 422);
        }

        $statement = database()->prepare(
            'INSERT INTO user_schedules (user_id, occurrence_id, schedule_state)
             VALUES (:user_id, :occurrence_id, :schedule_state)
             ON DUPLICATE KEY UPDATE schedule_state = VALUES(schedule_state), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'user_id' => $userId,
            'occurrence_id' => $occurrenceId,
            'schedule_state' => $state,
        ]);
        jsonResponse([
            'data' => [
                'occurrence_id' => $occurrenceId,
                'state' => $state,
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        ]);
    }

    $statement = database()->prepare(
        'DELETE FROM user_schedules WHERE user_id = :user_id AND occurrence_id = :occurrence_id'
    );
    $statement->execute(['user_id' => $userId, 'occurrence_id' => $occurrenceId]);
    http_response_code(204);
    exit;
}

errorResponse('NOT_FOUND', '指定されたAPIが見つかりません。', 404);
