<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();

function respond(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    $env = [];
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
        $value = getenv($key);
        if ($value === false || ($value === '' && $key !== 'DB_PASSWORD')) {
            throw new RuntimeException('Database configuration missing');
        }
        $env[$key] = $value;
    }
    if (!ctype_digit($env['DB_PORT']) || (int)$env['DB_PORT'] < 1 || (int)$env['DB_PORT'] > 65535) {
        throw new RuntimeException('Invalid database port');
    }
    $db = new PDO(
        'mysql:host=' . $env['DB_HOST'] . ';port=' . (int)$env['DB_PORT'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4',
        $env['DB_USERNAME'],
        $env['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $db->exec('CREATE TABLE IF NOT EXISTS game_accounts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        characters MEDIUMTEXT NOT NULL,
        version INT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        $result = ['csrf' => $_SESSION['csrf'], 'user' => null];
        if (isset($_SESSION['user_id'])) {
            $stmt = $db->prepare('SELECT username, characters, version FROM game_accounts WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $characters = json_decode($row['characters']);
                if (!is_object($characters)) throw new RuntimeException('Invalid saved data');
                $result['user'] = $row['username'];
                $result['characters'] = $characters;
                $result['version'] = (int)$row['version'];
            } else {
                unset($_SESSION['user_id']);
            }
        }
        respond(200, $result);
    }
    if ($method !== 'POST') respond(405, ['error' => 'Method not allowed.']);
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        respond(403, ['error' => 'Session expired. Reload the page.']);
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 2 * 1024 * 1024) respond(413, ['error' => 'Save is too large.']);
    $input = json_decode($raw);
    if (!is_object($input) || !isset($input->action) || !is_string($input->action)) {
        respond(400, ['error' => 'Invalid request.']);
    }

    if ($input->action === 'register' || $input->action === 'login') {
        $username = $input->username ?? null;
        $password = $input->password ?? null;
        if (!is_string($username) || !preg_match('/\A[A-Za-z0-9_]{1,32}\z/', $username) ||
            !is_string($password) || strlen($password) < 8 || strlen($password) > 72) {
            respond(400, ['error' => 'Use a 1–32 character username (letters, numbers, underscores) and an 8–72 byte password.']);
        }
        if ($input->action === 'register') {
            try {
                $stmt = $db->prepare('INSERT INTO game_accounts (username, password_hash, characters) VALUES (?, ?, ?)');
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), '{}']);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) respond(409, ['error' => 'Username already exists.']);
                throw $e;
            }
            respond(201, ['ok' => true]);
        }
        $stmt = $db->prepare('SELECT id, username, password_hash, characters, version FROM game_accounts WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($password, $row['password_hash'])) {
            respond(401, ['error' => 'Invalid username or password.']);
        }
        $characters = json_decode($row['characters']);
        if (!is_object($characters)) throw new RuntimeException('Invalid saved data');
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$row['id'];
        respond(200, ['user' => $row['username'], 'characters' => $characters, 'version' => (int)$row['version']]);
    }
    if (!isset($_SESSION['user_id'])) respond(401, ['error' => 'Please log in again.']);
    if ($input->action === 'logout') {
        unset($_SESSION['user_id']);
        session_regenerate_id(true);
        respond(200, ['ok' => true]);
    }
    if ($input->action === 'save') {
        if (!isset($input->characters, $input->version) || !is_object($input->characters) ||
            !is_int($input->version) || $input->version < 0) {
            respond(400, ['error' => 'Invalid save data.']);
        }
        $characters = json_encode($input->characters, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($characters === false || strlen($characters) > 2 * 1024 * 1024) respond(413, ['error' => 'Save is too large.']);
        $stmt = $db->prepare('UPDATE game_accounts SET characters = ?, version = version + 1 WHERE id = ? AND version = ?');
        $stmt->execute([$characters, $_SESSION['user_id'], $input->version]);
        if ($stmt->rowCount() !== 1) respond(409, ['error' => 'Save conflict: this account was changed elsewhere. Reload to see the latest save.']);
        respond(200, ['version' => $input->version + 1]);
    }
    respond(400, ['error' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('Game database request failed: ' . get_class($e));
    respond(500, ['error' => 'Database unavailable. Please try again later.']);
}
