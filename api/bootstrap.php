<?php
// api/bootstrap.php: shared helpers for the auth endpoints.
// Include with:  require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// XAMPP defaults. Change these if your MySQL user or password differ.
const DB_HOST = 'localhost';
const DB_NAME = 'commutestay';
const DB_USER = 'root';
const DB_PASS = '';

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,       // JavaScript cannot read the session cookie
    'samesite' => 'Lax',      // not sent on cross-site POSTs
]);
session_start();

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    return $pdo;
}

function json_out(array $data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// Only accept POST requests whose body is JSON. A page on another site
// cannot send this kind of request with our cookie, which blocks CSRF.
function read_json(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['ok' => false, 'error' => 'POST required.'], 405);
    }
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($type, 'application/json') !== 0) {
        json_out(['ok' => false, 'error' => 'JSON required.'], 415);
    }
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        json_out(['ok' => false, 'error' => 'Invalid request.'], 400);
    }
    return $data;
}

function current_user() {
    if (empty($_SESSION['user_id'])) return null;
    $st = db()->prepare('SELECT id, full_name, email, role FROM users WHERE id = ?');
    $st->execute([$_SESSION['user_id']]);
    return $st->fetch() ?: null;
}

function start_session_for(int $userId) {
    session_regenerate_id(true);   // new session id on login, against fixation
    $_SESSION['user_id'] = $userId;
}

// Use at the top of protected endpoints: require_login(['owner','admin']);
function require_login(array $roles = []) {
    $u = current_user();
    if (!$u) json_out(['ok' => false, 'error' => 'Please log in.'], 401);
    if ($roles && !in_array($u['role'], $roles, true)) {
        json_out(['ok' => false, 'error' => 'Not allowed.'], 403);
    }
    return $u;
}
