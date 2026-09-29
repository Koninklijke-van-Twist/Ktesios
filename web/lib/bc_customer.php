<?php

declare(strict_types=1);

require_once __DIR__ . '/mimir_client.php';

/**
 * Business Central-klant: read-check en een schrijfstub.
 *
 * Schrijven mag alleen als $canWriteToBC === true (boolean). Ook dan doet dit
 * skelet geen live OData-POST. De bedoelde aanroep, voor Ariadne later:
 *
 *   POST {baseUrl}Company('Koninklijke van Twist')/AppCustomerCard
 *   Content-Type: application/json
 *   Authorization: service-account uit $auth (basic), niet in git
 *
 *   Velden zoals op AppCustomerCard (zelfde namen als Mercurius/AM-Hub):
 *     Name, Address, Post_Code, City, Country_Region_Code,
 *     Phone_No, E_Mail, VAT_Registration_No, ContactName,
 *     KVT_Chamber_Of_Commerce_No
 *
 *   Geen PATCH/merge in dit skelet: een afwijkende bestaande klant wordt
 *   niet stil overschreven.
 *
 * Lezen: Mímir-tabel AppCustomerCard wanneer $mimirApi gezet is, anders
 * web/fixtures/bc_customers.json. Een Mímir-fout valt niet terug op de fixture,
 * zodat een storing geen "klant bestaat niet" of een vals archief wordt.
 */

function ktesios_bc_customer_entity(): string
{
    return 'AppCustomerCard';
}

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
        ['bc' => 'ContactName', 'local' => 'contact', 'label' => 'Contactpersoon', 'kind' => 'text'],
        ['bc' => 'KVT_Chamber_Of_Commerce_No', 'local' => 'kvk', 'label' => 'KvK-nummer', 'kind' => 'kvk'],
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
        case 'kvk':
            return ktesios_norm_phone($value);
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
        'ContactName',
        'KVT_Chamber_Of_Commerce_No',
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
        $byNo = ktesios_mimir_query(ktesios_bc_customer_entity(), ktesios_odata_eq('No', $keys['no']), $select, 60);
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
            ktesios_bc_customer_entity(),
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

        $syncBefore = ktesios_sync_snapshot($request);
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
                $request = ktesios_archive_request(
                    $request,
                    'Klant bestaat in Business Central en komt overeen met de goedgekeurde aanvraag.',
                    'matched'
                );
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

        $syncAfter = ktesios_sync_snapshot($request);
        if ($syncAfter !== $syncBefore && function_exists('ktesios_append_message')) {
            $activity = ktesios_describe_sync_activity($syncBefore, $request);
            $request = ktesios_append_message($request, ktesios_actor(), $activity, 'system', ktesios_actor_name());
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
 * Payload die een latere AppCustomerCard-insert zou meesturen. Nog geen HTTP.
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
        'entity' => ktesios_bc_customer_entity(),
        'company' => ktesios_company_name(),
        'operation' => 'insert',
        'requestId' => (string) ($request['id'] ?? ''),
        'fields' => [
            'Name' => (string) ($customer['name'] ?? ''),
            'Address' => (string) ($customer['address'] ?? ''),
            'Post_Code' => (string) ($customer['postCode'] ?? ''),
            'City' => (string) ($customer['city'] ?? ''),
            'Country_Region_Code' => $country,
            'Phone_No' => (string) ($customer['phone'] ?? ''),
            'E_Mail' => (string) ($customer['email'] ?? ''),
            'VAT_Registration_No' => (string) ($customer['vat'] ?? ''),
            'ContactName' => (string) ($customer['contact'] ?? ''),
            'KVT_Chamber_Of_Commerce_No' => (string) ($customer['kvk'] ?? ''),
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
        'url' => $base . "/Company('" . $company . "')/" . ktesios_bc_customer_entity(),
        'entity' => ktesios_bc_customer_entity(),
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
        'reason' => 'OData POST naar AppCustomerCard is in dit skelet niet geactiveerd. Alleen een dry-run log.',
        'payload' => $payload,
        'intended' => ktesios_bc_intended_request($payload),
    ];
    ktesios_bc_append_write_log($entry);
    return $entry;
}

/**
 * Zelfde afronding als een match in Business Central en als de schrijf-stub:
 * status archived, tijdstip, reden en bc-notitie. De aanvraag komt daardoor
 * in het archief en is niet meer goed te keuren.
 *
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function ktesios_archive_request(array $request, string $reason, string $bcSync, string $at = ''): array
{
    if ($at === '') {
        $at = gmdate('c');
    }
    $request['status'] = 'archived';
    $request['archivedAt'] = $at;
    $request['archiveReason'] = $reason;
    $request['bcSync'] = $bcSync;
    $request['bcNote'] = $reason;
    return $request;
}

/**
 * Goedkeuren.
 * Schrijven uit: status approved + dry-run payload, niet archiveren, write niet aanroepen.
 * Schrijven aan: stub-log en daarna archiveren. Nog steeds geen live POST.
 *
 * @param array<string, mixed> $request
 * @return array{ok: bool, mode: string, error: string, request: array<string, mixed>, result: array<string, mixed>}
 */
function ktesios_approve_request(array $request, string $actor, string $actorName = ''): array
{
    if ((string) ($request['status'] ?? '') === 'archived') {
        return [
            'ok' => false,
            'mode' => '',
            'error' => 'Een gearchiveerde aanvraag kan niet worden goedgekeurd.',
            'request' => $request,
            'result' => [],
        ];
    }
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
            'request' => ktesios_record_approval_activity($updated, $actor, $actorName),
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

    $updated = ktesios_archive_request(
        $updated,
        'Goedgekeurd. Schrijven naar Business Central is als stub gelogd; er is geen live OData-POST gedaan.',
        'stub-archived',
        $now
    );
    $updated['bcWrite'] = [
        'mode' => 'stub',
        'live' => false,
        'at' => (string) ($write['at'] ?? $now),
    ];

    return [
        'ok' => true,
        'mode' => 'stub',
        'error' => '',
        'request' => ktesios_record_approval_activity($updated, $actor, $actorName),
        'result' => $write,
    ];
}

function ktesios_current_email(): string
{
    return strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
}

function ktesios_actor(): string
{
    $email = ktesios_current_email();
    if ($email !== '') {
        return $email;
    }
    return 'lokaal';
}

function ktesios_actor_name(): string
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user)) {
        return '';
    }
    $email = ktesios_current_email();
    $keys = ['name', 'displayName', 'display_name', 'Naam', 'naam', 'full_name'];
    foreach ($keys as $key) {
        if (!isset($user[$key]) || !is_string($user[$key])) {
            continue;
        }
        $name = function_exists('ktesios_normalize_actor_name')
            ? ktesios_normalize_actor_name($user[$key])
            : trim($user[$key]);
        if ($name === '') {
            continue;
        }
        if ($email !== '' && strtolower($name) === $email) {
            continue;
        }
        return $name;
    }
    return '';
}

/**
 * @param array<string, mixed> $request
 * @return array<string, string>
 */
function ktesios_sync_snapshot(array $request): array
{
    return [
        'status' => (string) ($request['status'] ?? ''),
        'bcSync' => (string) ($request['bcSync'] ?? ''),
        'bcNote' => (string) ($request['bcNote'] ?? ''),
        'bcCustomerNo' => (string) ($request['bcCustomerNo'] ?? ''),
        'archiveReason' => (string) ($request['archiveReason'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function ktesios_record_approval_activity(array $request, string $actor, string $actorName): array
{
    if (!function_exists('ktesios_append_message') || !function_exists('ktesios_describe_approval')) {
        return $request;
    }
    return ktesios_append_message($request, $actor, ktesios_describe_approval($request), 'system', $actorName);
}

/**
 * Afkeuren: verplichte reden als activiteitsregel, daarna dezelfde archiefstap
 * als een match of de schrijf-stub. Alleen een open aanvraag.
 *
 * @param array<string, mixed> $request
 * @return array{ok: bool, error: string, request: array<string, mixed>}
 */
function ktesios_reject_request(array $request, string $actor, string $reason, string $actorName = ''): array
{
    $unchanged = [
        'ok' => false,
        'error' => '',
        'request' => $request,
    ];
    if ((string) ($request['status'] ?? '') === 'archived') {
        $unchanged['error'] = 'Een gearchiveerde aanvraag kan niet worden afgekeurd.';
        return $unchanged;
    }
    if ((string) ($request['status'] ?? '') !== 'open') {
        $unchanged['error'] = 'Alleen een open aanvraag kan worden afgekeurd.';
        return $unchanged;
    }
    if (!function_exists('ktesios_describe_rejection') || !function_exists('ktesios_append_message')) {
        $unchanged['error'] = 'Afkeuren is niet gelukt.';
        return $unchanged;
    }
    $text = ktesios_describe_rejection($reason);
    if ($text === '') {
        $unchanged['error'] = 'Vul een reden in om af te keuren.';
        return $unchanged;
    }
    $line = function_exists('ktesios_clip') ? ktesios_clip($reason, 1000, true) : trim($reason);
    $updated = ktesios_archive_request($request, $line, 'rejected');
    $updated = ktesios_append_message($updated, $actor, $text, 'system', $actorName);
    return [
        'ok' => true,
        'error' => '',
        'request' => $updated,
    ];
}

/**
 * De knop Goedkeuren hoort alleen bij een open aanvraag van een goedkeurder.
 * Na archiveren — afkeuren, een BC-match of de schrijf-stub — blijft hij weg.
 *
 * @param array<string, mixed> $request
 */
function ktesios_may_approve_request(array $request): bool
{
    if ((string) ($request['status'] ?? '') === 'archived') {
        return false;
    }
    if ((string) ($request['status'] ?? '') !== 'open') {
        return false;
    }
    return ktesios_can_approve();
}

/**
 * Fail-closed: ontbreekt $approvers, is die geen lijst, of staat het adres er
 * niet als string in, dan mag deze gebruiker niet goedkeuren, afkeuren of wijzigen.
 */
function ktesios_can_approve(): bool
{
    $email = ktesios_current_email();
    if ($email === '' || !isset($GLOBALS['approvers']) || !is_array($GLOBALS['approvers']) || $GLOBALS['approvers'] === []) {
        return false;
    }
    foreach ($GLOBALS['approvers'] as $entry) {
        if (!is_string($entry)) {
            continue;
        }
        if (strtolower(trim($entry)) === $email) {
            return true;
        }
    }
    return false;
}

function ktesios_approver_denied_message(): string
{
    return 'Alleen een aangewezen goedkeurder mag een aanvraag goedkeuren, afkeuren of wijzigen.';
}

function ktesios_requester_hint(): string
{
    return 'Je kunt een nieuwe aanvraag indienen. Goedkeuren, afkeuren en wijzigen mag alleen een aangewezen goedkeurder.';
}

/**
 * Vrij bericht. Iedereen die de aanvraag mag zien, dus niet alleen een goedkeurder.
 * Het CSRF-token wordt hier gecontroleerd, vóór er een bericht bij komt.
 *
 * @param list<array<string, mixed>> $requests
 * @param array<string, mixed> $request
 * @param array<string, mixed> $post
 * @return array{saved: bool, rotate: bool, error: string, requests: list<array<string, mixed>>}
 */
function ktesios_apply_message_action(array $requests, array $request, array $post, bool $csrfOk): array
{
    $none = [
        'saved' => false,
        'rotate' => false,
        'error' => '',
        'requests' => $requests,
    ];
    if (!$csrfOk) {
        $none['error'] = 'Deze actie hoort niet bij je sessie. Laad de pagina opnieuw.';
        return $none;
    }
    $text = isset($post['text']) && is_string($post['text']) ? $post['text'] : '';
    $added = ktesios_add_user_message($request, ktesios_actor(), $text, ktesios_actor_name());
    if ($added['ok'] !== true) {
        $none['error'] = $added['error'] !== '' ? $added['error'] : 'Bericht is niet opgeslagen.';
        $none['rotate'] = true;
        return $none;
    }
    return [
        'saved' => true,
        'rotate' => true,
        'error' => '',
        'requests' => ktesios_replace_request($requests, $added['request']),
    ];
}

/**
 * POST op de detailpagina. Goedkeuren, afkeuren en wijzigen eisen een goedkeurder
 * én een geldig CSRF-token. Een vrij bericht mag iedereen die de pagina ziet, met CSRF.
 * De controle gebeurt hier, niet alleen in de HTML.
 *
 * @param list<array<string, mixed>> $requests
 * @param array<string, mixed> $request
 * @param array<string, mixed> $post
 * @return array{saved: bool, rotate: bool, error: string, requests: list<array<string, mixed>>}
 */
function ktesios_apply_request_action(array $requests, array $request, array $post, bool $csrfOk): array
{
    $none = [
        'saved' => false,
        'rotate' => false,
        'error' => '',
        'requests' => $requests,
    ];
    $actie = (string) ($post['actie'] ?? '');
    if ($actie === 'bericht') {
        return ktesios_apply_message_action($requests, $request, $post, $csrfOk);
    }
    if ($actie !== 'goedkeuren' && $actie !== 'afkeuren' && $actie !== 'wijzigen') {
        $none['error'] = 'Onbekende actie.';
        return $none;
    }
    if (!ktesios_can_approve()) {
        $none['error'] = ktesios_approver_denied_message();
        return $none;
    }
    if (!$csrfOk) {
        $none['error'] = 'Deze actie hoort niet bij je sessie. Laad de pagina opnieuw.';
        return $none;
    }
    if ($actie === 'goedkeuren') {
        if ((string) ($post['bevestig'] ?? '') !== 'ja') {
            $none['error'] = 'Bevestig de goedkeuring in het venster.';
            $none['rotate'] = true;
            return $none;
        }
        $decision = ktesios_approve_request($request, ktesios_actor(), ktesios_actor_name());
        if ($decision['ok'] !== true) {
            $none['error'] = $decision['error'] !== '' ? $decision['error'] : 'Goedkeuren is niet gelukt.';
            $none['rotate'] = true;
            return $none;
        }
        return [
            'saved' => true,
            'rotate' => true,
            'error' => '',
            'requests' => ktesios_replace_request($requests, $decision['request']),
        ];
    }

    if ($actie === 'afkeuren') {
        if ((string) ($post['bevestig'] ?? '') !== 'ja') {
            $none['error'] = 'Bevestig de afkeuring in het venster.';
            $none['rotate'] = true;
            return $none;
        }
        $reason = isset($post['reden']) && is_string($post['reden']) ? $post['reden'] : '';
        $rejected = ktesios_reject_request($request, ktesios_actor(), $reason, ktesios_actor_name());
        if ($rejected['ok'] !== true) {
            $none['error'] = $rejected['error'] !== '' ? $rejected['error'] : 'Afkeuren is niet gelukt.';
            $none['rotate'] = true;
            return $none;
        }
        return [
            'saved' => true,
            'rotate' => true,
            'error' => '',
            'requests' => ktesios_replace_request($requests, $rejected['request']),
        ];
    }

    $edited = ktesios_edit_open_request($request, $post, ktesios_actor(), ktesios_actor_name());
    if ($edited['ok'] !== true) {
        $none['error'] = $edited['error'] !== '' ? $edited['error'] : 'Wijzigen is niet gelukt.';
        $none['rotate'] = true;
        return $none;
    }
    return [
        'saved' => true,
        'rotate' => true,
        'error' => '',
        'requests' => ktesios_replace_request($requests, $edited['request']),
    ];
}

/**
 * @param array<string, mixed> $request
 */
function ktesios_status_label(array $request): string
{
    $status = (string) ($request['status'] ?? '');
    $sync = (string) ($request['bcSync'] ?? '');
    if ($status === 'archived') {
        if ($sync === 'rejected') {
            return 'Afgekeurd';
        }
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
        if ($sync === 'rejected') {
            return 'rejected';
        }
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
