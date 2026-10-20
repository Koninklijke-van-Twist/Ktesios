<?php

declare(strict_types=1);

/**
 * Zelfde poort als Vulcanus/Asclepius: de gedeelde Login-app staat als sibling
 * op de server (…/login/lib.php). Lokaal (127.0.0.1 / ::1) slaan we dat over,
 * zodat `php -S` zonder Entra werkt.
 *
 * Na een geslaagde toegangscheck logt elke paginaload één bezoek via
 * web/analytics/analytics.php (zelfde HTTP-call als Clio). Localhost slaat
 * dat over, net als de login. De map zelf is niet opvraagbaar: ontbreekt
 * web/analytics/.htaccess, dan schrijft deze poort die (Require all denied,
 * behalve analytics.php).
 */

function ktesios_analytics_htaccess_rules(): string
{
    return <<<'HTACCESS'
# Alleen analytics.php is het HTTP-endpoint. De rest van deze map is niet opvraagbaar.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
<Files "analytics.php">
    <IfModule mod_authz_core.c>
        Require all granted
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order deny,allow
        Allow from all
    </IfModule>
</Files>
HTACCESS;
}

function ktesios_ensure_analytics_htaccess(?string $dir = null): void
{
    $dir = $dir ?? (__DIR__ . '/analytics');
    if (!is_dir($dir)) {
        return;
    }

    $path = $dir . '/.htaccess';
    if (is_file($path)) {
        return;
    }

    if (!is_writable($dir)) {
        @chmod($dir, 0775);
    }
    if (!is_writable($dir)) {
        return;
    }

    file_put_contents($path, ktesios_analytics_htaccess_rules() . "\n", LOCK_EX);
}

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
    ktesios_ensure_analytics_htaccess();

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

    $analyticsEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    $analyticsApiKey = trim((string) ($_SESSION['user']['api_key'] ?? ''));
    $analyticsOid = strtolower(trim((string) ($_SESSION['user']['oid'] ?? '')));
    if ($analyticsEmail !== '' && $analyticsApiKey !== '' && $analyticsOid !== '') {
        $analyticsScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $analyticsHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $analyticsBase = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
        $analyticsUrl = $analyticsScheme . '://' . $analyticsHost . $analyticsBase . '/analytics/analytics.php?' . http_build_query([
            'user_email' => $analyticsEmail,
            'api_key' => $analyticsApiKey,
            'oid' => $analyticsOid,
        ], '', '&', PHP_QUERY_RFC3986);

        if (function_exists('curl_init')) {
            $analyticsCurl = curl_init($analyticsUrl);
            curl_setopt_array($analyticsCurl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_TIMEOUT => 1,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_HTTPHEADER => ['X-API-Key: ' . $analyticsApiKey],
            ]);
            curl_exec($analyticsCurl);
            curl_close($analyticsCurl);
        }
    }
}
