<?php

declare(strict_types=1);

/**
 * JSON-opslag voor klantaanvragen onder web/data/ (gitignored, niet gedeployed-gewist).
 * Ontbreekt het bestand, dan wordt web/fixtures/requests_seed.json gekopieerd.
 */

function ktesios_requests_path(): string
{
    if (isset($GLOBALS['ktesios_requests_path']) && is_string($GLOBALS['ktesios_requests_path']) && $GLOBALS['ktesios_requests_path'] !== '') {
        return $GLOBALS['ktesios_requests_path'];
    }
    return dirname(__DIR__) . '/data/requests.json';
}

function ktesios_requests_seed_path(): string
{
    if (isset($GLOBALS['ktesios_requests_seed_path']) && is_string($GLOBALS['ktesios_requests_seed_path']) && $GLOBALS['ktesios_requests_seed_path'] !== '') {
        return $GLOBALS['ktesios_requests_seed_path'];
    }
    return dirname(__DIR__) . '/fixtures/requests_seed.json';
}

function ktesios_valid_request_id(string $id): bool
{
    return preg_match('/\AKA-\d{4}-\d{3}\z/', $id) === 1;
}

/**
 * @return list<array<string, mixed>>
 */
function ktesios_load_requests(): array
{
    $path = ktesios_requests_path();
    if (!is_file($path)) {
        ktesios_seed_requests();
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Aanvragen konden niet worden gelezen.');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Aanvragenbestand is geen geldige JSON-lijst.');
    }
    $out = [];
    foreach ($decoded as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }
    return $out;
}

function ktesios_seed_requests(): void
{
    $path = ktesios_requests_path();
    ktesios_ensure_dir(dirname($path));
    $seed = ktesios_requests_seed_path();
    if (!is_file($seed)) {
        ktesios_save_requests([]);
        return;
    }
    $raw = file_get_contents($seed);
    if ($raw === false) {
        throw new RuntimeException('Voorbeeld-aanvragen konden niet worden gelezen.');
    }
    if (file_put_contents($path, $raw, LOCK_EX) === false) {
        throw new RuntimeException('Aanvragen konden niet worden weggeschreven.');
    }
}

/**
 * Exclusive lock voor de reeks laden → vergelijken → wegschrijven.
 * flock is niet recursief: niet opnieuw aanroepen vanuit de callback.
 *
 * @template T
 * @param callable(): T $callback
 * @return T
 */
function ktesios_with_requests_lock(callable $callback)
{
    $dir = dirname(ktesios_requests_path());
    ktesios_ensure_dir($dir);
    $handle = fopen(ktesios_requests_path() . '.lock', 'c');
    if ($handle === false) {
        throw new RuntimeException('Aanvragen konden niet worden vergrendeld.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Aanvragen konden niet worden vergrendeld.');
        }
        return $callback();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * @param list<array<string, mixed>> $requests
 */
function ktesios_save_requests(array $requests): void
{
    $path = ktesios_requests_path();
    $dir = dirname($path);
    ktesios_ensure_dir($dir);
    $json = json_encode(array_values($requests), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Aanvragen konden niet worden gecodeerd.');
    }
    $tmp = tempnam($dir, '.requests-');
    if ($tmp === false) {
        throw new RuntimeException('Tijdelijk aanvragenbestand kon niet worden aangemaakt.');
    }
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException('Tijdelijk aanvragenbestand kon niet worden geschreven.');
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Aanvragen konden niet worden vervangen.');
    }
}

/**
 * @param list<array<string, mixed>> $requests
 * @return array<string, mixed>|null
 */
function ktesios_find_request(array $requests, string $id): ?array
{
    foreach ($requests as $request) {
        if ((string) ($request['id'] ?? '') === $id) {
            return $request;
        }
    }
    return null;
}

/**
 * @param list<array<string, mixed>> $requests
 * @param array<string, mixed> $updated
 * @return list<array<string, mixed>>
 */
function ktesios_replace_request(array $requests, array $updated): array
{
    $id = (string) ($updated['id'] ?? '');
    foreach ($requests as $index => $request) {
        if ((string) ($request['id'] ?? '') === $id) {
            $requests[$index] = $updated;
            return $requests;
        }
    }
    $requests[] = $updated;
    return $requests;
}

function ktesios_ensure_dir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Kan map niet aanmaken: ' . $dir);
    }
}
