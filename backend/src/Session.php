<?php

declare(strict_types=1);

function startApiSession(): void
{
    $isProduction = (getenv('APP_ENV') ?: '') === 'production';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('oshisuke_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isProduction,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function currentUserId(): ?int
{
    $userId = $_SESSION['user_id'] ?? null;
    return is_int($userId) ? $userId : null;
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function requireAuthenticatedUser(): int
{
    $userId = currentUserId();
    if ($userId === null) {
        errorResponse('UNAUTHENTICATED', 'ログインが必要です。', 401);
    }

    $statement = database()->prepare("SELECT id FROM users WHERE id = :id AND status = 'active'");
    $statement->execute(['id' => $userId]);
    if ($statement->fetch() === false) {
        destroyUserSession();
        errorResponse('UNAUTHENTICATED', 'このアカウントは現在利用できません。', 401);
    }

    return $userId;
}

function requireStaffUser(): array
{
    $userId = requireAuthenticatedUser();
    $statement = database()->prepare(
        "SELECT id, role FROM users WHERE id = :id AND status = 'active'"
    );
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch();
    if ($user === false || !in_array($user['role'], ['admin', 'moderator'], true)) {
        errorResponse('FORBIDDEN', '運営権限が必要です。', 403);
    }
    return ['id' => (int) $user['id'], 'role' => $user['role']];
}

function requireCsrfToken(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($provided) || !hash_equals(csrfToken(), $provided)) {
        errorResponse('CSRF_TOKEN_MISMATCH', '画面を再読み込みして、もう一度お試しください。', 403);
    }
}

function jsonRequestBody(): array
{
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        errorResponse('UNSUPPORTED_MEDIA_TYPE', 'JSON形式で送信してください。', 415);
    }

    $maximumBytes = 1024 * 1024;
    $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
    if ($contentLength !== false && $contentLength > $maximumBytes) {
        errorResponse('REQUEST_TOO_LARGE', '送信内容が大きすぎます。', 413);
    }

    $rawBody = file_get_contents('php://input', false, null, 0, $maximumBytes + 1);
    if ($rawBody === false || $rawBody === '') {
        return [];
    }
    if (strlen($rawBody) > $maximumBytes) {
        errorResponse('REQUEST_TOO_LARGE', '送信内容が大きすぎます。', 413);
    }

    try {
        $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        errorResponse('INVALID_JSON', '送信内容を読み取れませんでした。', 400);
    }

    if (!is_array($body)) {
        errorResponse('INVALID_JSON', '送信内容を読み取れませんでした。', 400);
    }

    return $body;
}

function assertAuthRateLimit(): void
{
    $now = time();
    $windowStartedAt = $_SESSION['auth_window_started_at'] ?? $now;
    $attempts = $_SESSION['auth_attempts'] ?? 0;

    if (!is_int($windowStartedAt) || $now - $windowStartedAt >= 600) {
        $windowStartedAt = $now;
        $attempts = 0;
    }

    if (!is_int($attempts)) {
        $attempts = 0;
    }

    if ($attempts >= 10) {
        errorResponse('RATE_LIMITED', 'しばらく待ってから、もう一度お試しください。', 429);
    }

    $_SESSION['auth_window_started_at'] = $windowStartedAt;
    $_SESSION['auth_attempts'] = $attempts + 1;
}

function clearAuthRateLimit(): void
{
    unset($_SESSION['auth_window_started_at'], $_SESSION['auth_attempts']);
}

function establishUserSession(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    clearAuthRateLimit();
}

function destroyUserSession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $parameters = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $parameters['path'],
            'domain' => $parameters['domain'],
            'secure' => $parameters['secure'],
            'httponly' => $parameters['httponly'],
            'samesite' => $parameters['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}
