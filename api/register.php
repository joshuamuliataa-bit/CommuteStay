<?php
require __DIR__ . '/bootstrap.php';

try {
    $in = read_json();
    $name  = trim((string)($in['full_name'] ?? ''));
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $pass  = (string)($in['password'] ?? '');
    $role  = (string)($in['role'] ?? 'student');

    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        json_out(['ok' => false, 'error' => 'Enter your full name (2 to 100 characters).'], 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        json_out(['ok' => false, 'error' => 'Enter a valid email address.'], 422);
    }
    if (strlen($pass) < 8 || strlen($pass) > 72) {
        json_out(['ok' => false, 'error' => 'Password must be 8 to 72 characters.'], 422);
    }
    // Admin accounts can never be created from this form.
    if (!in_array($role, ['student', 'owner'], true)) $role = 'student';

    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetch()) {
        json_out(['ok' => false, 'error' => 'That email is already registered. Try logging in.'], 409);
    }

    $ins = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)');
    $ins->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
    $id = (int)$pdo->lastInsertId();

    start_session_for($id);
    json_out(['ok' => true, 'user' => ['id' => $id, 'full_name' => $name, 'email' => $email, 'role' => $role]]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {   // duplicate email, two requests at once
        json_out(['ok' => false, 'error' => 'That email is already registered.'], 409);
    }
    error_log('register.php: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Could not create the account.'], 500);
} catch (Throwable $e) {
    error_log('register.php: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Could not create the account.'], 500);
}
