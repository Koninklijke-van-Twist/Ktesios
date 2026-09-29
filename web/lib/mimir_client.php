<?php

declare(strict_types=1);

/**
 * Read-only Mímir-client voor Ktesios.
 *
 * Zelfde aanroep als Vulcanus: POST {base}/query.php met company, table, filter.
 * Alleen de Customer-read gebruikt dit. Er is geen schrijfpad via Mímir.
 *
 * Optioneel in auth.php:
 *   $mimirBase    = 'https://sleutels.kvt.nl/mimir/api';
 *   $mimirCompany = 'Koninklijke van Twist';
 *
 * Redirects worden niet gevolgd, zodat Authorization niet naar een andere host gaat.
 */

function ktesios_mimir_api_key(): string
{
    global $mimirApi;
    if (!isset($mimirApi) || !is_string($mimirApi)) {
        return '';
    }
    return trim($mimirApi);
}

function ktesios_mimir_enabled(): bool
{
    return ktesios_mimir_api_key() !== '';
}

function ktesios_mimir_company(): string
{
    global $mimirCompany;
    if (isset($mimirCompany) && is_string($mimirCompany) && trim($mimirCompany) !== '') {
        return trim($mimirCompany);
    }
    return 'Koninklijke van Twist';
}

function ktesios_mimir_base_url(): string
{
    global $mimirBase;
    if (isset($mimirBase) && is_string($mimirBase) && trim($mimirBase) !== '') {
        $base = rtrim(trim($mimirBase), '/');
        if (!ktesios_mimir_base_url_allowed($base)) {
            throw new RuntimeException('Mímir-basis-URL moet https zijn ($mimirBase).');
        }
        return $base;
    }
    return 'https://sleutels.kvt.nl/mimir/api';
}

function ktesios_mimir_base_url_allowed(string $base): bool
{
    $scheme = parse_url($base, PHP_URL_SCHEME);
    if (!is_string($scheme)) {
        return false;
    }
    if (strcasecmp($scheme, 'https') === 0) {
        return true;
    }
    if (strcasecmp($scheme, 'http') !== 0) {
        return false;
    }
    $host = parse_url($base, PHP_URL_HOST);
    if (!is_string($host)) {
        return false;
    }
    $host = strtolower($host);
    return $host === '127.0.0.1' || $host === 'localhost' || $host === '::1';
}

function ktesios_odata_literal(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function ktesios_odata_eq(string $field, string $value): string
{
    return $field . ' eq ' . ktesios_odata_literal($value);
}

/**
 * Test-double: function (string $url, array $curlOptions): array{code: int, raw: string}
 */
function ktesios_mimir_set_transport(?callable $transport): void
{
    if ($transport === null) {
        unset($GLOBALS['ktesios_mimir_transport']);
        return;
    }
    $GLOBALS['ktesios_mimir_transport'] = $transport;
}

/**
 * @param array<string, mixed> $jsonBody
 * @return array<string, mixed>
 */
function ktesios_mimir_post(array $jsonBody): array
{
    $apiKey = ktesios_mimir_api_key();
    if ($apiKey === '') {
        throw new RuntimeException('Mímir API-sleutel ontbreekt ($mimirApi).');
    }

    $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Mímir request JSON encode mislukt.');
    }

    $url = ktesios_mimir_base_url() . '/query.php';
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
        'X-API-Key: ' . $apiKey,
    ];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_USERAGENT => 'Ktesios-MimirClient/1.0',
    ];

    $transport = $GLOBALS['ktesios_mimir_transport'] ?? null;
    if (is_callable($transport)) {
        $result = $transport($url, $options);
        if (!is_array($result) || !array_key_exists('code', $result) || !array_key_exists('raw', $result)) {
            throw new RuntimeException('Mímir test-transport gaf geen {code, raw} terug.');
        }
        $code = (int) $result['code'];
        $raw = (string) $result['raw'];
    } else {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is niet beschikbaar voor Mímir.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Mímir cURL init mislukt.');
        }
        curl_setopt_array($ch, $options);
        $rawExec = curl_exec($ch);
        if ($rawExec === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Mímir cURL error: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $raw = (string) $rawExec;
    }

    $decoded = json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        $message = is_array($decoded) ? (string) ($decoded['error'] ?? $raw) : $raw;
        throw new RuntimeException('Mímir HTTP ' . $code . ': ' . $message);
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('Mímir gaf ongeldige JSON terug.');
    }
    $errorField = $decoded['error'] ?? null;
    if ($errorField !== null && $errorField !== '' && $errorField !== false) {
        $message = is_string($errorField) ? $errorField : 'onbekende fout';
        throw new RuntimeException('Mímir error: ' . $message);
    }
    return $decoded;
}

/**
 * @param list<string> $select
 * @return list<array<string, mixed>>
 */
function ktesios_mimir_query(string $table, string $filter, array $select, int $maxAge = 60): array
{
    $body = [
        'company' => ktesios_mimir_company(),
        'table' => $table,
        'max_age' => max(0, $maxAge),
        'top' => 0,
    ];

    $cols = [];
    foreach ($select as $col) {
        if (!is_string($col)) {
            continue;
        }
        $col = trim($col);
        if ($col !== '') {
            $cols[] = $col;
        }
    }
    if ($cols !== []) {
        $body['select'] = $cols;
    }

    $filter = trim($filter);
    if ($filter !== '') {
        $body['filter'] = $filter;
    }

    $response = ktesios_mimir_post($body);
    if (!isset($response['value']) || !is_array($response['value'])) {
        throw new RuntimeException("Mímir query-antwoord mist 'value'.");
    }

    $rows = [];
    foreach ($response['value'] as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
    return $rows;
}
