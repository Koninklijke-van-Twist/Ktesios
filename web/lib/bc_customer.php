<?php

declare(strict_types=1);

require_once __DIR__ . '/mimir_client.php';

/**
 * Business Central-klant: read-check en een schrijfstub.
 *
 * Schrijven mag alleen als $canWriteToBC === true (boolean). Ook dan doet dit
 * skelet geen live OData-POST. De bedoelde aanroep, voor Ariadne later:
 *
 *   POST {baseUrl}Company('Koninklijke van Twist')/Customer
 *   Content-Type: application/json
 *   Authorization: service-account uit $auth (basic), niet in git
 *
 *   Velden (placeholder, pagina/naam door Ariadne te bevestigen):
 *     Name, Address, Post_Code, City, Country_Region_Code,
 *     Phone_No, E_Mail, VAT_Registration_No, Contact
 *
 *   KvK-nummer blijft lokaal tot er een BC-veld voor is. Geen PATCH/merge in
 *   dit skelet: een afwijkende bestaande klant wordt nooit stil overschreven.
 *
 * Lezen: Mímir-tabel Customer wanneer $mimirApi gezet is, anders
 * web/fixtures/bc_customers.json. Een Mímir-fout valt niet terug op de fixture,
 * zodat een storing geen "klant bestaat niet" of een vals archief wordt.
 */

function ktesios_can_write_to_bc(): bool
{
    if (!array_key_exists('canWriteToBC', $GLOBALS)) {
        return false;
    }
    return $GLOBALS['canWriteToBC'] === true;
}

function ktesios_company_name(): string
{
    return ktesios_mimir_company();
}

/**
 * @return list<array{bc: string, local: string, label: string, kind: string}>
 */
function ktesios_compare_fields(): array
{
    return [
        ['bc' => 'Name', 'local' => 'name', 'label' => 'Bedrijfsnaam', 'kind' => 'text'],
        ['bc' => 'Address', 'local' => 'address', 'label' => 'Adres', 'kind' => 'text'],
        ['bc' => 'Post_Code', 'local' => 'postCode', 'label' => 'Postcode', 'kind' => 'postcode'],
        ['bc' => 'City', 'local' => 'city', 'label' => 'Plaats', 'kind' => 'text'],
        ['bc' => 'Country_Region_Code', 'local' => 'country', 'label' => 'Land', 'kind' => 'upper'],
        ['bc' => 'Phone_No', 'local' => 'phone', 'label' => 'Telefoon', 'kind' => 'phone'],
        ['bc' => 'E_Mail', 'local' => 'email', 'label' => 'E-mail', 'kind' => 'lower'],
        ['bc' => 'VAT_Registration_No', 'local' => 'vat', 'label' => 'BTW-nummer', 'kind' => 'vat'],
        ['bc' => 'Contact', 'local' => 'contact', 'label' => 'Contactpersoon', 'kind' => 'text'],
    ];
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function ktesios_request_customer(array $request): array
{
    $customer = $request['customer'] ?? null;
    return is_array($customer) ? $customer : [];
}

function ktesios_lower(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }
    return strtolower($value);
}

function ktesios_norm_postcode(string $value): string
{
    $stripped = preg_replace('/\s+/', '', trim($value));
    return strtoupper($stripped ?? '');
}

function ktesios_norm_phone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value);
    return $digits ?? '';
}

function ktesios_norm_vat(string $value): string
{
    $compact = preg_replace('/[^A-Za-z0-9]/', '', $value);
    return strtoupper($compact ?? '');
}

function ktesios_norm_field(string $value, string $kind): string
{
    switch ($kind) {
        case 'postcode':
            return ktesios_norm_postcode($value);
        case 'phone':
            return ktesios_norm_phone($value);
        case 'vat':
            return ktesios_norm_vat($value);
        case 'upper':
            return strtoupper(trim($value));
        case 'lower':
            return ktesios_lower(trim($value));
        default:
            $collapsed = preg_replace('/\s+/u', ' ', trim($value));
            return ktesios_lower($collapsed ?? '');
    }
}

/**
 * @param array<string, mixed> $request
 * @return array{no: string, vat: string, vatRaw: string}
 */
function ktesios_lookup_keys(array $request): array
{
    $customer = ktesios_request_customer($request);
    $vatRaw = trim((string) ($customer['vat'] ?? ''));
    return [
        'no' => trim((string) ($customer['customerNo'] ?? '')),
        'vat' => ktesios_norm_vat($vatRaw),
        'vatRaw' => $vatRaw,
    ];
}

/**
 * @param array<string, mixed> $request
 * @param list<array<string, mixed>> $rows
 * @return array<string, mixed>|null
 */
function ktesios_match_customer_row(array $request, array $rows): ?array
{
    $keys = ktesios_lookup_keys($request);
    if ($keys['no'] !== '') {
        foreach ($rows as $row) {
            if (trim((string) ($row['No'] ?? '')) === $keys['no']) {
                return $row;
            }
        }
    }
    if ($keys['vat'] === '') {
        return null;
    }
    foreach ($rows as $row) {
        if (ktesios_norm_vat((string) ($row['VAT_Registration_No'] ?? '')) === $keys['vat']) {
            return $row;
        }
    }
    return null;
}

/**
 * @param array<string, mixed> $request
 * @param array<string, mixed> $bc
 * @return list<array{field: string, label: string, approved: string, bc: string}>
 */
function ktesios_bc_diff(array $request, array $bc): array
{
    $local = ktesios_request_customer($request);
    $diffs = [];
    foreach (ktesios_compare_fields() as $field) {
        $approved = (string) ($local[$field['local']] ?? '');
        $remote = (string) ($bc[$field['bc']] ?? '');
        if (ktesios_norm_field($approved, $field['kind']) === ktesios_norm_field($remote, $field['kind'])) {
            continue;
        }
        $diffs[] = [
            'field' => $field['bc'],
            'label' => $field['label'],
            'approved' => $approved,
            'bc' => $remote,
        ];
    }
    return $diffs;
}

function ktesios_bc_customers_path(): string
{
    if (isset($GLOBALS['ktesios_bc_customers_path']) && is_string($GLOBALS['ktesios_bc_customers_path']) && $GLOBALS['ktesios_bc_customers_path'] !== '') {
        return $GLOBALS['ktesios_bc_customers_path'];
    }
    return dirname(__DIR__) . '/fixtures/bc_customers.json';
}

/**
 * @return list<array<string, mixed>>
 */
function ktesios_sample_customers(): array
{
    $path = ktesios_bc_customers_path();
    if (!is_file($path)) {
        throw new RuntimeException('Samplebestand met klanten ontbreekt.');
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Samplebestand met klanten kon niet worden gelezen.');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Samplebestand met klanten is ongeldige JSON.');
    }
    $rows = [];
    foreach ($decoded as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * @param array<string, mixed> $request
 * @return array{found: bool, customer: array<string, mixed>|null, source: string, error: string}
 */
function ktesios_sample_find_customer(array $request): array
{
    try {
        $rows = ktesios_sample_customers();
    } catch (Throwable $error) {
        return [
            'found' => false,
            'customer' => null,
            'source' => 'sample',
            'error' => $error->getMessage(),
        ];
    }
    $match = ktesios_match_customer_row($request, $rows);
    return [
        'found' => $match !== null,
        'customer' => $match,
        'source' => 'sample',
        'error' => '',
    ];
}

/**
 * @return list<string>
 */
function ktesios_customer_select(): array
{
    return [
        'No',
        'Name',
        'Address',
        'Post_Code',
        'City',
        'Country_Region_Code',
        'Phone_No',
        'E_Mail',
        'VAT_Registration_No',
        'Contact',
    ];
}

/**
 * @param array<string, mixed> $request
 * @return list<array<string, mixed>>
 */
function ktesios_mimir_customer_rows(array $request): array
{
    $keys = ktesios_lookup_keys($request);
    $select = ktesios_customer_select();
    if ($keys['no'] !== '') {
        $byNo = ktesios_mimir_query('Customer', ktesios_odata_eq('No', $keys['no']), $select, 60);
        if ($byNo !== []) {
            return $byNo;
        }
    }
    if ($keys['vat'] !== '') {
        $vatFilter = $keys['vatRaw'] !== '' ? $keys['vatRaw'] : $keys['vat'];
        $compact = ktesios_norm_vat($vatFilter);
        if ($compact !== '' && $compact !== $vatFilter) {
            $vatFilter = $compact;
        }
        return ktesios_mimir_query(
            'Customer',
            ktesios_odata_eq('VAT_Registration_No', $vatFilter),
            $select,
            60
        );
    }
    return [];
}

/**
 * @param array<string, mixed> $request
 * @return array{found: bool, customer: array<string, mixed>|null, source: string, error: string}
 */
function ktesios_mimir_find_customer(array $request): array
{
    try {
        $rows = ktesios_mimir_customer_rows($request);
    } catch (Throwable $error) {
        return [
            'found' => false,
            'customer' => null,
            'source' => 'mimir',
            'error' => 'Mímir-controle mislukt: ' . $error->getMessage(),
        ];
    }
    $match = ktesios_match_customer_row($request, $rows);
    return [
        'found' => $match !== null,
        'customer' => $match,
        'source' => 'mimir',
        'error' => '',
    ];
}

/**
 * @param array<string, mixed> $request
 * @return array{found: bool, customer: array<string, mixed>|null, source: string, error: string}
 */
function ktesios_bc_find_customer(array $request): array
{
    if (ktesios_mimir_enabled()) {
        return ktesios_mimir_find_customer($request);
    }
    return ktesios_sample_find_customer($request);
}

function ktesios_bc_read_source_label(): string
{
    if (ktesios_mimir_enabled()) {
        return 'Mímir (alleen lezen)';
    }
    return 'sample-fixtures (geen $mimirApi)';
}

/**
 * @param list<array<string, mixed>> $requests
 * @return array{
 *   requests: list<array<string, mixed>>,
 *   warnings: list<array<string, mixed>>,
 *   archivedIds: list<string>,
 *   errors: list<array<string, mixed>>,
 *   changed: bool
 * }
 */
function ktesios_reconcile_requests(array $requests, ?callable $finder = null): array
{
    if ($finder === null) {
        $finder = 'ktesios_bc_find_customer';
    }

    $warnings = [];
    $errors = [];
    $archivedIds = [];
    $changed = false;

    foreach ($requests as $index => $request) {
        if (!is_array($request)) {
            continue;
        }
        if ((string) ($request['status'] ?? '') !== 'approved') {
            continue;
        }

        $before = json_encode($request, JSON_UNESCAPED_UNICODE);
        $lookup = $finder($request);
        if (!is_array($lookup)) {
            $lookup = [
                'found' => false,
                'customer' => null,
                'source' => '',
                'error' => 'Ongeldig controle-resultaat.',
            ];
        }

        $error = trim((string) ($lookup['error'] ?? ''));
        $request['bcSource'] = (string) ($lookup['source'] ?? '');

        if ($error !== '') {
            $request['bcSync'] = 'error';
            $request['bcNote'] = $error;
            $request['bcDiff'] = [];
            $errors[] = [
                'id' => (string) ($request['id'] ?? ''),
                'name' => (string) (ktesios_request_customer($request)['name'] ?? ''),
                'message' => $error,
            ];
        } elseif (($lookup['found'] ?? false) !== true || !is_array($lookup['customer'] ?? null)) {
            $request['bcSync'] = 'waiting';
            $request['bcNote'] = 'Klant staat nog niet in Business Central.';
            $request['bcDiff'] = [];
            $request['bcCustomerNo'] = '';
        } else {
            $customer = $lookup['customer'];
            $diff = ktesios_bc_diff($request, $customer);
            $request['bcCustomerNo'] = trim((string) ($customer['No'] ?? ''));
            if ($diff === []) {
                $request['status'] = 'archived';
                $request['archivedAt'] = gmdate('c');
                $request['archiveReason'] = 'Klant bestaat in Business Central en komt overeen met de goedgekeurde aanvraag.';
                $request['bcSync'] = 'matched';
                $request['bcNote'] = $request['archiveReason'];
                $request['bcDiff'] = [];
                $archivedIds[] = (string) ($request['id'] ?? '');
            } else {
                $request['bcSync'] = 'differs';
                $request['bcNote'] = 'Business Central wijkt af. Er is niets overschreven.';
                $request['bcDiff'] = $diff;
                $warnings[] = [
                    'id' => (string) ($request['id'] ?? ''),
                    'name' => (string) (ktesios_request_customer($request)['name'] ?? ''),
                    'customerNo' => $request['bcCustomerNo'],
                    'diff' => $diff,
                ];
            }
        }

        $after = json_encode($request, JSON_UNESCAPED_UNICODE);
        if ($after !== $before) {
            $changed = true;
        }
        $requests[$index] = $request;
    }

    return [
        'requests' => $requests,
        'warnings' => $warnings,
        'archivedIds' => $archivedIds,
        'errors' => $errors,
        'changed' => $changed,
    ];
}

/**
 * Payload die een latere Customer-insert zou meesturen. Nog geen HTTP.
 *
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function ktesios_bc_customer_payload(array $request): array
{
    $customer = ktesios_request_customer($request);
    $country = strtoupper(trim((string) ($customer['country'] ?? 'NL')));
    if ($country === '') {
        $country = 'NL';
    }

    return [
        'entity' => 'Customer',
        'company' => ktesios_company_name(),
        'operation' => 'insert',
        'requestId' => (string) ($request['id'] ?? ''),
        'kvkNotMapped' => (string) ($customer['kvk'] ?? ''),
        'fields' => [
            'Name' => (string) ($customer['name'] ?? ''),
            'Address' => (string) ($customer['address'] ?? ''),
            'Post_Code' => (string) ($customer['postCode'] ?? ''),
            'City' => (string) ($customer['city'] ?? ''),
            'Country_Region_Code' => $country,
            'Phone_No' => (string) ($customer['phone'] ?? ''),
            'E_Mail' => (string) ($customer['email'] ?? ''),
            'VAT_Registration_No' => (string) ($customer['vat'] ?? ''),
            'Contact' => (string) ($customer['contact'] ?? ''),
        ],
    ];
}

/**
 * Beschrijving van de OData-POST. Wordt getoond en gelogd, nooit verstuurd.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function ktesios_bc_intended_request(array $payload): array
{
    global $baseUrl;
    $base = '{baseUrl}';
    if (isset($baseUrl) && is_string($baseUrl) && trim($baseUrl) !== '') {
        $base = rtrim(trim($baseUrl), '/');
    }
    if (stripos($base, 'ODataV4') === false) {
        $base .= '/ODataV4';
    }
    $company = str_replace("'", "''", (string) ($payload['company'] ?? ktesios_company_name()));

    return [
        'method' => 'POST',
        'url' => $base . "/Company('" . $company . "')/Customer",
        'entity' => 'Customer',
        'headers' => [
            'Content-Type: application/json',
            'If-Match: *',
            'Authorization: Basic (service-account uit $auth — niet gelogd en niet verstuurd)',
        ],
        'body' => isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : [],
        'todo' => 'Ariadne: vervang ktesios_bc_write_customer door deze POST. Dit skelet roept hem niet aan.',
    ];
}

function ktesios_bc_write_log_path(): string
{
    if (isset($GLOBALS['ktesios_bc_write_log_path']) && is_string($GLOBALS['ktesios_bc_write_log_path']) && $GLOBALS['ktesios_bc_write_log_path'] !== '') {
        return $GLOBALS['ktesios_bc_write_log_path'];
    }
    return dirname(__DIR__) . '/data/bc-write.log';
}

/**
 * @param array<string, mixed> $entry
 */
function ktesios_bc_append_write_log(array $entry): void
{
    $path = ktesios_bc_write_log_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Logmap voor BC-schrijfacties ontbreekt.');
    }
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        throw new RuntimeException('BC-schrijflog kon niet worden gecodeerd.');
    }
    if (file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('BC-schrijflog kon niet worden bijgewerkt.');
    }
}

/**
 * Stub. Geen cURL, geen OData-POST, ook niet als $baseUrl en $auth gezet zijn.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function ktesios_bc_write_customer(array $payload, string $requestId = ''): array
{
    if (!ktesios_can_write_to_bc()) {
        return [
            'ok' => false,
            'mode' => 'blocked',
            'live' => false,
            'requestId' => $requestId,
            'reason' => 'Schrijven naar Business Central staat uit ($canWriteToBC is niet true).',
            'payload' => $payload,
        ];
    }

    $GLOBALS['ktesios_bc_write_invocations'] = (int) ($GLOBALS['ktesios_bc_write_invocations'] ?? 0) + 1;

    $entry = [
        'at' => gmdate('c'),
        'ok' => true,
        'mode' => 'stub',
        'live' => false,
        'requestId' => $requestId !== '' ? $requestId : (string) ($payload['requestId'] ?? ''),
        'reason' => 'OData POST naar Customer is in dit skelet niet geactiveerd. Alleen een dry-run log.',
        'payload' => $payload,
        'intended' => ktesios_bc_intended_request($payload),
    ];
    ktesios_bc_append_write_log($entry);
    return $entry;
}

/**
 * Goedkeuren.
 * Schrijven uit: status approved + dry-run payload, niet archiveren, write niet aanroepen.
 * Schrijven aan: stub-log en daarna archiveren. Nog steeds geen live POST.
 *
 * @param array<string, mixed> $request
 * @return array{ok: bool, mode: string, error: string, request: array<string, mixed>, result: array<string, mixed>}
 */
function ktesios_approve_request(array $request, string $actor): array
{
    if ((string) ($request['status'] ?? '') !== 'open') {
        return [
            'ok' => false,
            'mode' => '',
            'error' => 'Alleen een open aanvraag kan worden goedgekeurd.',
            'request' => $request,
            'result' => [],
        ];
    }

    $payload = ktesios_bc_customer_payload($request);
    $now = gmdate('c');
    $updated = $request;
    $updated['approvedAt'] = $now;
    $updated['approvedBy'] = $actor;
    $updated['dryRunPayload'] = $payload;

    if (!ktesios_can_write_to_bc()) {
        $updated['status'] = 'approved';
        $updated['bcSync'] = 'dry-run';
        $updated['bcNote'] = 'Goedgekeurd. Niets naar Business Central geschreven.';
        $updated['bcDiff'] = [];
        return [
            'ok' => true,
            'mode' => 'dry-run',
            'error' => '',
            'request' => $updated,
            'result' => [
                'ok' => true,
                'mode' => 'dry-run',
                'live' => false,
                'payload' => $payload,
                'intended' => ktesios_bc_intended_request($payload),
            ],
        ];
    }

    try {
        $write = ktesios_bc_write_customer($payload, (string) ($request['id'] ?? ''));
    } catch (Throwable $error) {
        return [
            'ok' => false,
            'mode' => 'stub',
            'error' => $error->getMessage(),
            'request' => $request,
            'result' => [],
        ];
    }

    if (($write['ok'] ?? false) !== true || ($write['live'] ?? true) !== false) {
        return [
            'ok' => false,
            'mode' => (string) ($write['mode'] ?? ''),
            'error' => 'Schrijfstub gaf geen veilig dry-run resultaat. Er is niets gearchiveerd.',
            'request' => $request,
            'result' => $write,
        ];
    }

    $updated['status'] = 'archived';
    $updated['archivedAt'] = $now;
    $updated['archiveReason'] = 'Goedgekeurd. Schrijven naar Business Central is als stub gelogd; er is geen live OData-POST gedaan.';
    $updated['bcSync'] = 'stub-archived';
    $updated['bcNote'] = $updated['archiveReason'];
    $updated['bcWrite'] = [
        'mode' => 'stub',
        'live' => false,
        'at' => (string) ($write['at'] ?? $now),
    ];

    return [
        'ok' => true,
        'mode' => 'stub',
        'error' => '',
        'request' => $updated,
        'result' => $write,
    ];
}

function ktesios_actor(): string
{
    $email = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    if ($email !== '') {
        return $email;
    }
    return 'lokaal';
}

/**
 * @param array<string, mixed> $request
 */
function ktesios_status_label(array $request): string
{
    $status = (string) ($request['status'] ?? '');
    $sync = (string) ($request['bcSync'] ?? '');
    if ($status === 'archived') {
        return 'Afgerond';
    }
    if ($status === 'approved') {
        if ($sync === 'differs') {
            return 'Afwijking in Business Central';
        }
        if ($sync === 'error') {
            return 'Controle mislukt';
        }
        return 'Goedgekeurd, wacht op Business Central';
    }
    return 'Open';
}

/**
 * @param array<string, mixed> $request
 */
function ktesios_status_pill(array $request): string
{
    $status = (string) ($request['status'] ?? '');
    $sync = (string) ($request['bcSync'] ?? '');
    if ($status === 'archived') {
        return 'archived';
    }
    if ($status === 'approved' && $sync === 'differs') {
        return 'differs';
    }
    if ($status === 'approved' && $sync === 'error') {
        return 'error';
    }
    if ($status === 'approved') {
        return 'waiting';
    }
    return 'open';
}
