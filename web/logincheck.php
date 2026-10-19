<?php

declare(strict_types=1);


require_once __DIR__ . '/lib/UserAccess.php';
require_once __DIR__ . '/lib/AllowedUsersStore.php';

function kvt_managed_allowed_emails(): array
{
    try {
        return (new AllowedUsersStore(__DIR__ . '/data/access.sqlite'))->listAllowedEmails();
    } catch (Throwable) {
        return [];
    }
}

function kvt_session_email(): string
{
    return strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
}

/**
 * true = toegang ok; false = weigeren. null = gebruik de oorspronkelijke auth.php-check.
 */
function kvt_access_decision(?array $authAllowedUsers = null): ?bool
{
    $email = kvt_session_email();
    if (kvt_email_is_admin($email)) {
        return true;
    }
    $managed = kvt_managed_allowed_emails();
    if ($managed !== []) {
        return in_array($email, $managed, true);
    }

    return null;
}
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

    $kvtDecision = kvt_access_decision(isset($allowedUsers) && is_array($allowedUsers) ? $allowedUsers : null);
    if ($kvtDecision === false) {
        require __DIR__ . '/../login/403.php';
        exit;
    }
    if ($kvtDecision === null) {
        $currentEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));

        // Geen of lege $allowedUsers = alle geldige Entra-logins hebben toegang.
        $allowList = (isset($allowedUsers) && is_array($allowedUsers)) ? $allowedUsers : [];
        $restrictToAllowList = count($allowList) > 0;

        $isAllowedUser = false;
        if (!$restrictToAllowList) {
            $isAllowedUser = ($currentEmail !== '');
        } else {
            foreach ($allowList as $emailKey => $value) {
                $allowedEmail = is_int($emailKey) ? (string) $value : (string) $emailKey;
                if (strtolower(trim($allowedEmail)) === $currentEmail && $currentEmail !== '') {
                    $isAllowedUser = true;
                    break;
                }
            }
        }

        if (!$isAllowedUser) {
            require __DIR__ . '/../login/403.php';
            exit;
        }
    }
}
