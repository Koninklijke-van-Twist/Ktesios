<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

$email = strtolower(trim((string) ($_GET['email'] ?? '')));
if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    http_response_code(400);
    exit;
}

if (!ktesios_ensure_user_avatar($email)) {
    http_response_code(500);
    exit;
}

$path = ktesios_user_avatar_path($email);
if (!ktesios_avatar_file_is_safe($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
readfile($path);
