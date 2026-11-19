<?php
require __DIR__ . '/bootstrap.php';

try {
    $in = read_json();
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $pass  = (string)($in['password'] ?? '');

    // Slow down repeated guessing from the same browser session.
    $_SESSION['fails'] = $_SESSION['fails'] ?? 0;
    if ($_SESSION['fails'] >= 5) sleep(2);

    $st = db()->prepare('SELECT id, full_name, email, password_hash, role FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();

    // Same message for "no such email" and "wrong password".
    if (!$u || !password_verify($pass, $u['password_hash'])) {
        $_SESSION['fails']++;
        json_out(['ok' => false, 'error' => 'Incorrect email or password.'], 401);
    }

    $_SESSION['fails'] = 0;
    start_session_for((int)$u['id']);
    json_out(['ok' => true, 'user' => [
        'id' => (int)$u['id'], 'full_name' => $u['full_name'],
        'email' => $u['email'], 'role' => $u['role'],
    ]]);
} catch (Throwable $e) {
    error_log('login.php: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Could not log in.'], 500);
}
