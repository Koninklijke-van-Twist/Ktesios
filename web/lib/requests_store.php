<?php

declare(strict_types=1);

/**
 * JSON-opslag voor klantaanvragen onder web/data/ (gitignored, niet gedeployed-gewist).
 * Ontbreekt de map, dan wordt die aangemaakt. Ontbreekt het bestand, dan wordt
 * web/fixtures/requests_seed.json gekopieerd vóór de exclusieve lock.
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
 * @return list<string>
 */
function ktesios_customer_field_keys(): array
{
    return ['customerNo', 'name', 'address', 'postCode', 'city', 'country', 'phone', 'email', 'vat', 'kvk', 'contact'];
}

function ktesios_clip(string $value, int $max, bool $singleLine): string
{
    $value = str_replace("\0", '', $value);
    if ($singleLine) {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
        $collapsed = preg_replace('/[ \t]+/u', ' ', $value);
        $value = trim($collapsed ?? '');
    } else {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }
    if (strlen($value) <= $max) {
        return $value;
    }
    return substr($value, 0, $max);
}

/**
 * @param array<string, mixed> $input
 * @param array<string, mixed> $existing
 * @return array<string, string>
 */
function ktesios_customer_from_input(array $input, array $existing = []): array
{
    $posted = isset($input['customer']) && is_array($input['customer']) ? $input['customer'] : [];
    $customer = [];
    foreach (ktesios_customer_field_keys() as $key) {
        if (!array_key_exists($key, $posted)) {
            $customer[$key] = ktesios_clip((string) ($existing[$key] ?? ''), 200, true);
            continue;
        }
        $raw = $posted[$key];
        $customer[$key] = ktesios_clip(is_string($raw) ? $raw : '', 200, true);
    }
    if ($customer['country'] === '') {
        $customer['country'] = 'NL';
    }
    return $customer;
}

/**
 * @param list<array<string, mixed>> $requests
 */
function ktesios_next_request_id(array $requests): string
{
    $year = gmdate('Y');
    $max = 0;
    $pattern = '/\AKA-' . $year . '-(\d{3})\z/';
    foreach ($requests as $request) {
        $id = (string) ($request['id'] ?? '');
        if (preg_match($pattern, $id, $match) === 1) {
            $number = (int) $match[1];
            if ($number > $max) {
                $max = $number;
            }
        }
    }
    $next = $max + 1;
    if ($next > 999) {
        return '';
    }
    return sprintf('KA-%s-%03d', $year, $next);
}

/**
 * Nieuwe aanvraag. Geen goedkeurder nodig.
 *
 * @param list<array<string, mixed>> $requests
 * @param array<string, mixed> $input
 * @return array{ok: bool, error: string, request: array<string, mixed>, requests: list<array<string, mixed>>}
 */
function ktesios_create_open_request(array $requests, array $input, string $actor): array
{
    $customer = ktesios_customer_from_input($input);
    if ($customer['name'] === '') {
        return ['ok' => false, 'error' => 'Vul een bedrijfsnaam in.', 'request' => [], 'requests' => $requests];
    }
    $id = ktesios_next_request_id($requests);
    if ($id === '') {
        return ['ok' => false, 'error' => 'Er kan geen nieuw aanvraagnummer worden gemaakt.', 'request' => [], 'requests' => $requests];
    }
    $note = '';
    if (isset($input['note']) && is_string($input['note'])) {
        $note = ktesios_clip($input['note'], 1000, false);
    }
    $request = [
        'id' => $id,
        'status' => 'open',
        'createdAt' => gmdate('c'),
        'createdBy' => $actor,
        'approvedAt' => '',
        'approvedBy' => '',
        'archivedAt' => '',
        'archiveReason' => '',
        'bcSync' => '',
        'bcNote' => '',
        'bcCustomerNo' => '',
        'bcDiff' => [],
        'note' => $note,
        'customer' => $customer,
    ];
    $requests[] = $request;
    return ['ok' => true, 'error' => '', 'request' => $request, 'requests' => $requests];
}

/**
 * @param array<string, mixed> $request
 * @param array<string, mixed> $input
 * @return array{ok: bool, error: string, request: array<string, mixed>}
 */
function ktesios_edit_open_request(array $request, array $input): array
{
    if ((string) ($request['status'] ?? '') !== 'open') {
        return ['ok' => false, 'error' => 'Alleen een open aanvraag kan worden gewijzigd.', 'request' => $request];
    }
    $existingCustomer = $request['customer'] ?? null;
    $existing = is_array($existingCustomer) ? $existingCustomer : [];
    $customer = ktesios_customer_from_input($input, $existing);
    if ($customer['name'] === '') {
        return ['ok' => false, 'error' => 'Vul een bedrijfsnaam in.', 'request' => $request];
    }
    $request['customer'] = $customer;
    if (isset($input['note']) && is_string($input['note'])) {
        $request['note'] = ktesios_clip($input['note'], 1000, false);
    }
    return ['ok' => true, 'error' => '', 'request' => $request];
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
    $dir = dirname($path);
    ktesios_ensure_dir($dir);
    if (is_file($path)) {
        return;
    }
    $seed = ktesios_requests_seed_path();
    if (is_file($seed)) {
        $raw = file_get_contents($seed);
        if ($raw === false) {
            throw new RuntimeException('Voorbeeld-aanvragen konden niet worden gelezen. Geprobeerd pad: ' . $seed . '.');
        }
    } else {
        $raw = "[]\n";
    }
    ktesios_create_store_file($path, $raw);
}

/**
 * Schrijf het storebestand alleen als het nog niet bestaat.
 * link() en fopen-modus xb maken het doel exclusief. rename() zou een
 * bestaande lijst vervangen, ook als een ander verzoek die al had
 * vergrendeld, vergeleken en opgeslagen.
 */
function ktesios_create_store_file(string $path, string $contents): void
{
    if (is_file($path)) {
        return;
    }
    $dir = dirname($path);
    error_clear_last();
    $tmp = @tempnam($dir, '.seed-');
    $written = is_string($tmp) ? @file_put_contents($tmp, $contents) : false;
    if ($tmp === false || $written === false) {
        $message = ktesios_io_hint(
            'Aanvragen konden niet worden weggeschreven. Geprobeerd pad: ' . $path
            . '. Maak de map schrijfbaar voor de webserver: ' . $dir . '.'
        );
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        throw new RuntimeException($message);
    }
    if (is_file($path)) {
        @unlink($tmp);
        return;
    }
    if (@link($tmp, $path)) {
        @unlink($tmp);
        return;
    }
    @unlink($tmp);
    if (is_file($path)) {
        return;
    }
    ktesios_create_store_exclusive($path, $contents);
}

/**
 * Tweede poging als deze schijf geen harde links kent. Modus xb faalt als het
 * bestand intussen bestaat, en vervangt het dan niet.
 */
function ktesios_create_store_exclusive(string $path, string $contents): void
{
    if (is_file($path)) {
        return;
    }
    $dir = dirname($path);
    error_clear_last();
    $handle = @fopen($path, 'xb');
    if ($handle === false) {
        if (is_file($path)) {
            return;
        }
        throw new RuntimeException(ktesios_io_hint(
            'Aanvragen konden niet worden weggeschreven. Geprobeerd pad: ' . $path
            . '. Maak de map schrijfbaar voor de webserver: ' . $dir . '.'
        ));
    }
    $written = @fwrite($handle, $contents);
    @fflush($handle);
    @fclose($handle);
    if (is_int($written) && $written === strlen($contents)) {
        return;
    }
    if (is_file($path) && filesize($path) === $written) {
        @unlink($path);
    }
    throw new RuntimeException(ktesios_io_hint(
        'Aanvragen konden niet worden weggeschreven. Geprobeerd pad: ' . $path
        . '. Maak de map schrijfbaar voor de webserver: ' . $dir . '.'
    ));
}

function ktesios_io_hint(string $message): string
{
    $last = error_get_last();
    if (!is_array($last) || !isset($last['message']) || !is_string($last['message']) || $last['message'] === '') {
        return $message;
    }
    return $message . ' Systeem: ' . $last['message'] . '.';
}

/**
 * Exclusive lock voor de reeks laden → vergelijken → wegschrijven.
 * flock is niet recursief: niet opnieuw aanroepen vanuit de callback.
 *
 * Eerst map aanmaken (en schrijfbaar maken als wij de eigenaar zijn) en, als
 * requests.json ontbreekt, de voorbeeldlijst kopiëren. Daarna pas flock.
 * Lukt het slot nog steeds niet, dan stopt de pagina met het geprobeerde pad.
 * We gaan niet zonder slot verder: de controle schrijft statussen weg, en twee
 * schrijvers zouden het bestand anders overschrijven. Alleen tonen zou die
 * race verbergen tot iemand goedkeurt of archiveert.
 *
 * @template T
 * @param callable(): T $callback
 * @return T
 */
function ktesios_with_requests_lock(callable $callback)
{
    $path = ktesios_requests_path();
    $dir = dirname($path);
    ktesios_ensure_dir($dir);
    if (!is_file($path)) {
        ktesios_seed_requests();
    }
    $lockPath = $path . '.lock';
    error_clear_last();
    $handle = @fopen($lockPath, 'c');
    if ($handle === false) {
        throw new RuntimeException(ktesios_io_hint(
            'Aanvragen konden niet worden vergrendeld. Geprobeerd pad: ' . $lockPath
            . '. Het lockbestand kon niet worden geopend. Maak de map schrijfbaar voor de webserver en probeer opnieuw: '
            . $dir . '.'
        ));
    }
    try {
        error_clear_last();
        if (!@flock($handle, LOCK_EX)) {
            throw new RuntimeException(ktesios_io_hint(
                'Aanvragen konden niet worden vergrendeld. Geprobeerd pad: ' . $lockPath
                . '. Exclusieve vergrendeling (flock) is geweigerd. Maak de map schrijfbaar voor de webserver ('
                . $dir
                . '), of zet het project op een lokale schijf als dit een netwerkschijf of synchronisatiemap is.'
            ));
        }
        if (!is_file($path)) {
            ktesios_seed_requests();
        }
        return $callback();
    } finally {
        @flock($handle, LOCK_UN);
        @fclose($handle);
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
    error_clear_last();
    $tmp = @tempnam($dir, '.requests-');
    if ($tmp === false) {
        throw new RuntimeException(ktesios_io_hint(
            'Tijdelijk aanvragenbestand kon niet worden aangemaakt. Geprobeerd map: ' . $dir
            . '. Maak die map schrijfbaar voor de webserver.'
        ));
    }
    error_clear_last();
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException(ktesios_io_hint(
            'Tijdelijk aanvragenbestand kon niet worden geschreven. Geprobeerd pad: ' . $tmp . '.'
        ));
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException(ktesios_io_hint(
            'Aanvragen konden niet worden vervangen. Geprobeerd pad: ' . $path . '.'
        ));
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
    if (!is_dir($dir)) {
        error_clear_last();
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(ktesios_io_hint(
                'Kan map niet aanmaken: ' . $dir . '. Maak de bovenliggende map schrijfbaar voor de webserver.'
            ));
        }
    }
    if (is_dir($dir) && !is_writable($dir)) {
        @chmod($dir, 0775);
    }
}
