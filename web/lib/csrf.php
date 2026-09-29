<?php

declare(strict_types=1);

/**
 * CSRF-token in de bestaande PHP-sessie.
 * Op sleutels.kvt.nl opent login/lib.php die sessie en sluit hem daarna.
 * Lokaal is er nog geen sessie; dan starten we er zelf een met dezelfde
 * cookie-vlaggen (httponly, SameSite=Lax).
 */

function ktesios_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (function_exists('configure_app_session')) {
        configure_app_session();
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function ktesios_csrf_token(): string
{
    ktesios_session_start();
    $current = $_SESSION['ktesios_csrf'] ?? '';
    if (!is_string($current) || preg_match('/\A[a-f0-9]{64}\z/', $current) !== 1) {
        $current = bin2hex(random_bytes(32));
        $_SESSION['ktesios_csrf'] = $current;
    }
    return $current;
}

function ktesios_csrf_valid(string $posted): bool
{
    ktesios_session_start();
    $current = $_SESSION['ktesios_csrf'] ?? '';
    if (!is_string($current) || $current === '' || $posted === '') {
        return false;
    }
    return hash_equals($current, $posted);
}

function ktesios_csrf_rotate(): void
{
    ktesios_session_start();
    unset($_SESSION['ktesios_csrf']);
}
