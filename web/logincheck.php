<?php

declare(strict_types=1);

/**
 * Zelfde poort als Vulcanus/Asclepius: de gedeelde Login-app staat als sibling
 * op de server (…/login/lib.php). Lokaal (127.0.0.1 / ::1) slaan we dat over,
 * zodat `php -S` zonder Entra werkt.
 *
 * Ktesios heeft geen analytics-endpoint; die aanroep uit Vulcanus zit hier niet in.
 */

function is_trusted_requester(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $server = $_SERVER['SERVER_ADDR'] ?? '';
    $trusted = ['127.0.0.1', '::1'];
    if ($remote === $server && $remote !== '') {
        return true;
    }
    if (in_array($remote, $trusted, true)) {
        return true;
    }
    return false;
}

if (!is_trusted_requester()) {
    require __DIR__ . '/../login/lib.php';

    $currentEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));

    // Geen of lege $allowedUsers = alle geldige Entra-logins hebben toegang.
    $allowList = (isset($allowedUsers) && is_array($allowedUsers)) ? $allowedUsers : [];
    $restrictToAllowList = count($allowList) > 0;

    $isAllowedUser = false;
    if (!$restrictToAllowList) {
        $isAllowedUser = ($currentEmail !== '');
    } else {
        foreach ($allowList as $emailKey => $value) {
            // Legacy ['user@domain'] én nieuw ['user@domain' => [15, 40]].
            $allowedEmail = is_int($emailKey) ? (string) $value : (string) $emailKey;
            if (strtolower(trim($allowedEmail)) === $currentEmail && $currentEmail !== '') {
                $isAllowedUser = true;
                break;
            }
        }
    }

    if (!$isAllowedUser) {
        require __DIR__ . '/../login/403.php';
        die();
    }
}
