<?php
require __DIR__ . '/bootstrap.php';
read_json();   // enforces POST + JSON
$_SESSION = [];
session_destroy();
json_out(['ok' => true]);
