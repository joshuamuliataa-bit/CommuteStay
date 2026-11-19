<?php
require __DIR__ . '/bootstrap.php';
try {
    json_out(['ok' => true, 'user' => current_user()]);
} catch (Throwable $e) {
    error_log('me.php: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Could not check login.'], 500);
}
