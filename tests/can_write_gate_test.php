<?php

/**
 * Schrijfgate, dry-run, reconciliatie en de stub-write.
 * Geen netwerk en geen web/auth.php.
 *
 *   php tests/can_write_gate_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../web/lib/bc_customer.php';
require_once __DIR__ . '/../web/lib/requests_store.php';

$failures = 0;

function check(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        fwrite(STDOUT, "OK  {$message}\n");
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL {$message}\n");
}

function reset_state(): void
{
    unset(
        $GLOBALS['canWriteToBC'],
        $GLOBALS['mimirApi'],
        $GLOBALS['mimirBase'],
        $GLOBALS['mimirCompany'],
        $GLOBALS['baseUrl'],
        $GLOBALS['auth'],
        $GLOBALS['ktesios_bc_write_invocations'],
        $GLOBALS['ktesios_requests_path'],
        $GLOBALS['ktesios_requests_seed_path'],
        $GLOBALS['ktesios_bc_write_log_path'],
        $GLOBALS['ktesios_bc_customers_path'],
        $GLOBALS['approvers']
    );
    ktesios_mimir_set_transport(null);
}

/**
 * @return array<string, mixed>
 */
function sample_request(string $id, string $status = 'open'): array
{
    return [
        'id' => $id,
        'status' => $status,
        'createdAt' => '2026-09-20T09:15:00Z',
        'approvedAt' => '',
        'approvedBy' => '',
        'archivedAt' => '',
        'archiveReason' => '',
        'bcSync' => '',
        'bcNote' => '',
        'bcCustomerNo' => '',
        'bcDiff' => [],
        'note' => '',
        'customer' => [
            'customerNo' => '',
            'name' => 'Smit & Zonen B.V.',
            'address' => 'Kade 3',
            'postCode' => '3511 AB',
            'city' => 'Utrecht',
            'country' => 'NL',
            'phone' => '030-5550101',
            'email' => 'facturen@smitenzonen.example',
            'vat' => 'NL111222333B01',
            'kvk' => '30222111',
            'contact' => 'C. Smit',
        ],
    ];
}

reset_state();

check(ktesios_can_write_to_bc() === false, 'undefined flag blocks writes');
$GLOBALS['canWriteToBC'] = false;
check(ktesios_can_write_to_bc() === false, 'false blocks writes');
$GLOBALS['canWriteToBC'] = 1;
check(ktesios_can_write_to_bc() === false, 'integer 1 is not the boolean true');
$GLOBALS['canWriteToBC'] = 'true';
check(ktesios_can_write_to_bc() === false, 'string true is not the boolean true');
$GLOBALS['canWriteToBC'] = 'false';
check(ktesios_can_write_to_bc() === false, 'string false blocks writes');
$GLOBALS['canWriteToBC'] = true;
check(ktesios_can_write_to_bc() === true, 'only boolean true opens the write path');

$template = (string) file_get_contents(__DIR__ . '/../web/auth_TEMPLATE.php');
check(preg_match('/\$canWriteToBC\s*=\s*false\s*;/', $template) === 1, 'template defaults the flag to false');
check(preg_match('/\$approvers\s*=\s*\[\s*\]\s*;/', $template) === 1, 'template fail-closes approvers to an empty list');
check(strpos($template, 'mimir_…') !== false || strpos($template, 'mimir_...') !== false, 'template has no live Mímir key');
check(strpos($template, "'pass' => 'PASSWORD'") !== false, 'BC password in the template is only the placeholder');
check(strpos($template, 'web/auth.php') !== false, 'template tells you to copy it to auth.php');

$gitignore = (string) file_get_contents(__DIR__ . '/../.gitignore');
check(strpos($gitignore, 'web/auth.php') !== false, 'gitignore ignores web/auth.php');
check(strpos($gitignore, 'web/data/*') !== false, 'gitignore ignores local data files');
check(strpos($gitignore, '!web/data/.gitkeep') !== false, 'gitignore keeps the data directory marker');

$workflow = (string) file_get_contents(__DIR__ . '/../.github/workflows/deploy-ftp.yml');
check(strpos($workflow, 'scripts/guard-ftp-remote-dir.sh') !== false, 'deploy workflow runs the remote-dir guard');
check(strpos($workflow, 'FTP_REMOTE_DIR=/var/www/html/ktesios') !== false, 'workflow documents the expected remote dir');
check(strpos($workflow, '--exclude-glob auth.php') !== false, 'deploy excludes auth.php');
check(strpos($workflow, '--exclude-glob .htaccess') !== false, 'deploy excludes htaccess');
check(strpos($workflow, '--exclude-glob data/**') !== false, 'deploy excludes data');
check(strpos($workflow, '--exclude-glob cache/**') !== false, 'deploy excludes cache');
check(strpos($workflow, 'mirror -R --delete') !== false, 'deploy mirrors web with delete');
check(strpos($workflow, "php-version: \"8.0\"") !== false, 'CI runs the gate test on PHP 8.0');
check(strpos($workflow, 'actions/checkout@v4') === false, 'checkout is not a floating major tag');
check(preg_match_all('/actions\/checkout@[0-9a-f]{40}/', $workflow) === 2, 'both checkout steps are pinned to a full SHA');
check(preg_match('/shivammathur\/setup-php@[0-9a-f]{40}/', $workflow) === 1, 'setup-php is pinned to a full SHA');
check(strpos($workflow, 'set ftp:ssl-allow false') !== false, 'deploy stays on plain FTP like the other sleutels apps');
check(strpos($workflow, 'TLS uit:') !== false, 'workflow notes that TLS-off matches the org FTP servers');

$bcSource = (string) file_get_contents(__DIR__ . '/../web/lib/bc_customer.php');
check(strpos($bcSource, 'curl_exec') === false, 'write module does not call curl_exec');
check(strpos($bcSource, 'curl_init') === false, 'write module does not open a curl handle');
check(strpos($bcSource, "entity' => 'Customer'") !== false || strpos($bcSource, "'entity' => 'Customer'") !== false, 'intended entity is Customer');

$login = (string) file_get_contents(__DIR__ . '/../web/logincheck.php');
check(strpos($login, "/../login/lib.php") !== false, 'logincheck uses the shared Login app');
check(strpos($login, 'is_trusted_requester') !== false, 'localhost skips the shared login');

reset_state();
$blocked = ktesios_bc_write_customer(['fields' => ['Name' => 'Niemand']], 'KA-2026-010');
check(($blocked['mode'] ?? '') === 'blocked', 'direct write without the flag is blocked');
check(($blocked['live'] ?? true) === false, 'blocked write is not live');
check((int) ($GLOBALS['ktesios_bc_write_invocations'] ?? 0) === 0, 'blocked write does not count as a stub invocation');

$tmp = sys_get_temp_dir() . '/ktesios-test-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "cannot create temp dir\n");
    exit(1);
}
$GLOBALS['ktesios_bc_write_log_path'] = $tmp . '/bc-write.log';
$GLOBALS['ktesios_requests_path'] = $tmp . '/requests.json';
$GLOBALS['ktesios_bc_customers_path'] = __DIR__ . '/../web/fixtures/bc_customers.json';

$open = sample_request('KA-2026-010', 'open');
$dry = ktesios_approve_request($open, 'tester@kvt.example');
check($dry['ok'] === true && $dry['mode'] === 'dry-run', 'approval without the flag is a dry-run');
check(($dry['request']['status'] ?? '') === 'approved', 'dry-run approval stays approved');
check(($dry['request']['status'] ?? '') !== 'archived', 'dry-run approval does not archive');
check(($dry['request']['bcSync'] ?? '') === 'dry-run', 'dry-run marks bcSync');
$payload = $dry['result']['payload'] ?? [];
check(is_array($payload) && (($payload['entity'] ?? '') === 'Customer'), 'dry-run payload names Customer');
check((($payload['fields']['Name'] ?? '') === 'Smit & Zonen B.V.'), 'dry-run payload keeps the company name');
check((($payload['fields']['VAT_Registration_No'] ?? '') === 'NL111222333B01'), 'dry-run payload keeps the VAT number');
check((($payload['kvkNotMapped'] ?? '') === '30222111'), 'KvK stays outside the BC field list');
check(!isset($payload['fields']['kvk']) && !isset($payload['fields']['Kvk']), 'KvK is not sent as a BC field');
check(is_file($GLOBALS['ktesios_bc_write_log_path']) === false, 'dry-run approval does not write the BC log');
check((int) ($GLOBALS['ktesios_bc_write_invocations'] ?? 0) === 0, 'dry-run approval does not call the stub writer');

$again = ktesios_approve_request($dry['request'], 'tester@kvt.example');
check($again['ok'] === false, 'an approved request cannot be approved twice');

$afterReconcile = ktesios_reconcile_requests([$dry['request']]);
$reconciledDry = $afterReconcile['requests'][0];
check(($reconciledDry['status'] ?? '') === 'approved', 'reconcile keeps a dry-run approval that is not in BC');
check(($reconciledDry['bcSync'] ?? '') === 'waiting', 'reconcile marks the missing customer as waiting');
check(isset($reconciledDry['dryRunPayload']['fields']['Name']), 'reconcile keeps the dry-run payload for the detail page');

reset_state();
$GLOBALS['canWriteToBC'] = true;
$GLOBALS['baseUrl'] = 'https://api.businesscentral.dynamics.com/v2.0/tenant/Production/ODataV4/';
$GLOBALS['auth'] = ['mode' => 'basic', 'user' => 'SVC', 'pass' => 'geheim-niet-loggen'];
$GLOBALS['ktesios_bc_write_log_path'] = $tmp . '/bc-write.log';
$stub = ktesios_approve_request(sample_request('KA-2026-011', 'open'), 'tester@kvt.example');
check($stub['ok'] === true && $stub['mode'] === 'stub', 'boolean true uses the stub writer');
check(($stub['request']['status'] ?? '') === 'archived', 'stub approval archives the request');
check(($stub['result']['live'] ?? true) === false, 'stub result is not live');
check(($stub['result']['mode'] ?? '') === 'stub', 'stub result mode is stub');
check((int) ($GLOBALS['ktesios_bc_write_invocations'] ?? 0) === 1, 'stub writer ran once');
$log = (string) file_get_contents($GLOBALS['ktesios_bc_write_log_path']);
check(strpos($log, '"live":false') !== false, 'log records live false');
check(strpos($log, '"mode":"stub"') !== false, 'log records stub mode');
check(strpos($log, 'geheim-niet-loggen') === false, 'log does not contain the BC password');
check(strpos($log, 'KA-2026-011') !== false, 'log names the request');
check(strpos($log, 'ODataV4') !== false, 'log keeps the intended OData URL');
check(strpos($log, 'curl_') === false, 'log is not an HTTP transcript');

$intended = ktesios_bc_intended_request($stub['result']['payload']);
check(($intended['method'] ?? '') === 'POST', 'intended call is POST');
check(strpos((string) ($intended['url'] ?? ''), "/Company('Koninklijke van Twist')/Customer") !== false, 'intended URL targets Customer');
check(strpos((string) ($intended['todo'] ?? ''), 'Ariadne') !== false, 'intended call is marked for Ariadne');

reset_state();
$GLOBALS['ktesios_bc_customers_path'] = __DIR__ . '/../web/fixtures/bc_customers.json';
$seedPath = __DIR__ . '/../web/fixtures/requests_seed.json';
$seed = json_decode((string) file_get_contents($seedPath), true);
check(is_array($seed), 'seed JSON loads');
$first = ktesios_reconcile_requests($seed);
$byId = [];
foreach ($first['requests'] as $row) {
    $byId[(string) $row['id']] = $row;
}
check(($byId['KA-2026-010']['status'] ?? '') === 'open', 'open request is not reconciled');
check(($byId['KA-2026-020']['status'] ?? '') === 'archived', 'matching approved customer is archived');
check(($byId['KA-2026-020']['bcSync'] ?? '') === 'matched', 'match marks bcSync matched');
check(($byId['KA-2026-020']['bcCustomerNo'] ?? '') === 'K00042', 'match stores the BC customer number');
check(strpos((string) ($byId['KA-2026-020']['archiveReason'] ?? ''), 'overeen') !== false, 'archive reason says the data matches');
check(($byId['KA-2026-030']['status'] ?? '') === 'approved', 'differing customer stays approved');
check(($byId['KA-2026-030']['bcSync'] ?? '') === 'differs', 'differing customer sets a warning state');
check(($byId['KA-2026-030']['customer']['address'] ?? '') === 'Havenkade 8', 'diff does not overwrite the approved address');
check(($byId['KA-2026-030']['customer']['email'] ?? '') === 'nieuw@vandijk.example', 'diff does not overwrite the approved email');
$diffFields = [];
foreach ($byId['KA-2026-030']['bcDiff'] as $diffRow) {
    $diffFields[] = (string) ($diffRow['field'] ?? '');
}
sort($diffFields);
check($diffFields === ['Address', 'E_Mail', 'Phone_No'], 'diff lists address, email and phone');
check(($byId['KA-2026-040']['status'] ?? '') === 'approved', 'missing customer stays approved');
check(($byId['KA-2026-040']['bcSync'] ?? '') === 'waiting', 'missing customer stays waiting');
check(($byId['KA-2026-050']['status'] ?? '') === 'archived', 'already archived request stays archived');
check(($byId['KA-2026-050']['bcCustomerNo'] ?? '') === 'K00011', 'already archived customer number is left alone');
check($first['archivedIds'] === ['KA-2026-020'], 'only the matching request is newly archived');
check(count($first['warnings']) === 1 && ($first['warnings'][0]['id'] ?? '') === 'KA-2026-030', 'one warning for the differing customer');
check($first['changed'] === true, 'first reconcile changes the seed');
check((int) ($GLOBALS['ktesios_bc_write_invocations'] ?? 0) === 0, 'reconcile never calls the writer');

$second = ktesios_reconcile_requests($first['requests']);
check($second['changed'] === false, 'second reconcile is stable');
check($second['archivedIds'] === [], 'second reconcile does not archive again');

$spaced = sample_request('KA-2026-020', 'approved');
$spaced['customer'] = [
    'customerNo' => '',
    'name' => 'De  Groot   Installaties B.V.',
    'address' => 'Industrieweg 12',
    'postCode' => '1234 ab',
    'city' => 'utrecht',
    'country' => 'nl',
    'phone' => '030 123 45 67',
    'email' => 'INFO@degroot-installaties.example',
    'vat' => 'nl123456789b01',
    'kvk' => '30123456',
    'contact' => 'A. de Groot',
];
$spacedResult = ktesios_reconcile_requests([$spaced]);
check(($spacedResult['requests'][0]['status'] ?? '') === 'archived', 'spacing and case still count as a match');

$byNo = sample_request('KA-2026-077', 'approved');
$byNo['customer']['customerNo'] = 'K00077';
$byNo['customer']['vat'] = 'NL000000000B00';
$byNo['customer']['name'] = 'Iets anders';
$byNoResult = ktesios_reconcile_requests([$byNo]);
check(($byNoResult['requests'][0]['bcSync'] ?? '') === 'differs', 'customer number wins over a different VAT');
check(($byNoResult['requests'][0]['bcCustomerNo'] ?? '') === 'K00077', 'lookup by customer number finds K00077');

$missingFile = $tmp . '/no-customers.json';
$GLOBALS['ktesios_bc_customers_path'] = $missingFile;
$errResult = ktesios_reconcile_requests([sample_request('KA-2026-040', 'approved')]);
check(($errResult['requests'][0]['status'] ?? '') === 'approved', 'a failed lookup does not archive');
check(($errResult['requests'][0]['bcSync'] ?? '') === 'error', 'a failed lookup is an error, not waiting');
check($errResult['errors'] !== [], 'failed lookup is reported');

reset_state();
$GLOBALS['mimirApi'] = 'mimir_test_key';
$GLOBALS['ktesios_bc_customers_path'] = __DIR__ . '/../web/fixtures/bc_customers.json';
$mimirCalls = 0;
ktesios_mimir_set_transport(static function (string $url, array $options) use (&$mimirCalls): array {
    $mimirCalls++;
    $body = json_decode((string) ($options[CURLOPT_POSTFIELDS] ?? ''), true);
    if (!is_array($body)) {
        $body = [];
    }
    if (($body['table'] ?? '') !== 'Customer') {
        return ['code' => 500, 'raw' => '{"error":"unexpected table"}'];
    }
    if (($body['filter'] ?? '') !== "VAT_Registration_No eq 'NL123456789B01'") {
        return ['code' => 200, 'raw' => '{"value":[]}'];
    }
    $row = [
        'No' => 'K00042',
        'Name' => 'De Groot Installaties B.V.',
        'Address' => 'Industrieweg 12',
        'Post_Code' => '1234 AB',
        'City' => 'Utrecht',
        'Country_Region_Code' => 'NL',
        'Phone_No' => '030-1234567',
        'E_Mail' => 'info@degroot-installaties.example',
        'VAT_Registration_No' => 'NL123456789B01',
        'Contact' => 'A. de Groot',
    ];
    return ['code' => 200, 'raw' => (string) json_encode(['value' => [$row]])];
});

$liveMatch = sample_request('KA-2026-020', 'approved');
$liveMatch['customer']['name'] = 'De Groot Installaties B.V.';
$liveMatch['customer']['address'] = 'Industrieweg 12';
$liveMatch['customer']['postCode'] = '1234 AB';
$liveMatch['customer']['city'] = 'Utrecht';
$liveMatch['customer']['phone'] = '030-1234567';
$liveMatch['customer']['email'] = 'info@degroot-installaties.example';
$liveMatch['customer']['vat'] = 'NL 1234.567.89 B01';
$liveMatch['customer']['contact'] = 'A. de Groot';
$live = ktesios_bc_find_customer($liveMatch);
check(($live['source'] ?? '') === 'mimir', 'configured Mímir is the read source');
check(($live['found'] ?? false) === true, 'Mímir row is found');
check(strpos((string) ($optionsProbe ?? ''), 'fixtures') === false, 'finder result is not the fixture path');
check($mimirCalls === 1, 'VAT lookup is a single Customer query');

$probe = null;
ktesios_mimir_set_transport(static function (string $url, array $options) use (&$probe): array {
    $probe = ['url' => $url, 'options' => $options];
    return ['code' => 200, 'raw' => '{"value":[]}'];
});
$empty = ktesios_bc_find_customer($liveMatch);
check(($empty['found'] ?? true) === false, 'empty Mímir value means the customer is absent');
check(($empty['source'] ?? '') === 'mimir', 'empty Mímir result does not fall back to fixtures');
check(($empty['error'] ?? 'x') === '', 'absence is not a transport error');
check(is_array($probe) && str_ends_with((string) $probe['url'], '/query.php'), 'Mímir posts to query.php');
check(($probe['options'][CURLOPT_FOLLOWLOCATION] ?? null) === false, 'Mímir redirects are not followed');
check(($probe['options'][CURLOPT_USERAGENT] ?? '') === 'Ktesios-MimirClient/1.0', 'Mímir user agent');
$headers = $probe['options'][CURLOPT_HTTPHEADER] ?? [];
check(in_array('Authorization: Bearer mimir_test_key', $headers, true), 'bearer header');
check(in_array('X-API-Key: mimir_test_key', $headers, true), 'API key header');

ktesios_mimir_set_transport(static function (): array {
    return ['code' => 503, 'raw' => '{"error":"bezet"}'];
});
$down = ktesios_reconcile_requests([$liveMatch]);
check(($down['requests'][0]['status'] ?? '') === 'approved', 'Mímir HTTP error does not archive a match');
check(($down['requests'][0]['bcSync'] ?? '') === 'error', 'Mímir HTTP error stays visible');
check(strpos((string) ($down['requests'][0]['bcNote'] ?? ''), '503') !== false, 'Mímir HTTP status is kept');

$GLOBALS['mimirBase'] = 'http://mimir.example/api';
$clearCalled = false;
ktesios_mimir_set_transport(static function () use (&$clearCalled): array {
    $clearCalled = true;
    return ['code' => 200, 'raw' => '{"value":[]}'];
});
$clear = ktesios_mimir_find_customer($liveMatch);
check($clearCalled === false, 'non-https Mímir base is rejected before the key is sent');
check(strpos((string) ($clear['error'] ?? ''), 'https') !== false, 'cleartext Mímir base explains https');

check(ktesios_odata_literal("O'Brien") === "'O''Brien'", 'OData quotes an apostrophe');
check(ktesios_valid_request_id('KA-2026-010') === true, 'request ids match KA-YYYY-NNN');
check(ktesios_valid_request_id('../auth.php') === false, 'request ids reject paths');

$GLOBALS['ktesios_requests_path'] = $tmp . '/store.json';
$stored = [sample_request('KA-2026-010', 'open')];
ktesios_save_requests($stored);
$loaded = ktesios_load_requests();
check(count($loaded) === 1 && ($loaded[0]['id'] ?? '') === 'KA-2026-010', 'store roundtrip');
$replaced = ktesios_replace_request($loaded, sample_request('KA-2026-010', 'approved'));
check(($replaced[0]['status'] ?? '') === 'approved', 'replace updates the same id');
check(ktesios_find_request($replaced, 'KA-2026-099') === null, 'missing id is null');

$lockHeld = false;
ktesios_with_requests_lock(static function () use (&$lockHeld): void {
    $handle = fopen(ktesios_requests_path() . '.lock', 'c');
    $lockHeld = $handle !== false && !flock($handle, LOCK_EX | LOCK_NB);
    if (is_resource($handle)) {
        fclose($handle);
    }
});
check($lockHeld === true, 'requests lock stays exclusive across the callback');

$freshDir = $tmp . '/fresh-data';
$GLOBALS['ktesios_requests_path'] = $freshDir . '/requests.json';
$GLOBALS['ktesios_requests_seed_path'] = __DIR__ . '/../web/fixtures/requests_seed.json';
$seededCount = 0;
ktesios_with_requests_lock(static function () use (&$seededCount): void {
    $seededCount = count(ktesios_load_requests());
});
check(is_dir($freshDir), 'missing data directory is created before the lock');
check(is_writable($freshDir), 'created data directory is writable');
check(is_file($freshDir . '/requests.json'), 'missing store is seeded from fixtures before the lock');
check($seededCount >= 1, 'locked load sees the seeded requests');
check(strpos((string) file_get_contents($freshDir . '/requests.json'), 'KA-2026-010') !== false, 'seed copy keeps the fixture ids');

$repairDir = $tmp . '/repair-data';
if (!mkdir($repairDir, 0555, true) && !is_dir($repairDir)) {
    fwrite(STDERR, "cannot create repair-data\n");
    exit(1);
}
$GLOBALS['ktesios_requests_path'] = $repairDir . '/requests.json';
$repaired = false;
try {
    ktesios_with_requests_lock(static function () use (&$repaired): void {
        $repaired = is_file(ktesios_requests_path());
    });
} catch (RuntimeException $error) {
    fwrite(STDERR, 'repair lock error: ' . $error->getMessage() . "\n");
}
check($repaired === true, 'owned data directory is made writable before the lock');
check(is_file($repairDir . '/requests.json'), 'seed runs after the directory is made writable');
@chmod($repairDir, 0775);

$keptDir = $tmp . '/kept-data';
if (!mkdir($keptDir, 0775, true) && !is_dir($keptDir)) {
    fwrite(STDERR, "cannot create kept-data\n");
    exit(1);
}
$keptPath = $keptDir . '/requests.json';
file_put_contents($keptPath, "[{\"id\":\"KA-2026-099\"}]\n");
$GLOBALS['ktesios_requests_path'] = $keptPath;
ktesios_with_requests_lock(static function (): void {
});
check(strpos((string) file_get_contents($keptPath), 'KA-2026-099') !== false, 'existing store is not reseeded');

$blockedDir = $tmp . '/lock-blocked';
if (!mkdir($blockedDir, 0775, true) && !is_dir($blockedDir)) {
    fwrite(STDERR, "cannot create lock-blocked\n");
    exit(1);
}
if (!mkdir($blockedDir . '/requests.json.lock', 0775, true) && !is_dir($blockedDir . '/requests.json.lock')) {
    fwrite(STDERR, "cannot create lock path blocker\n");
    exit(1);
}
$GLOBALS['ktesios_requests_path'] = $blockedDir . '/requests.json';
$lockError = '';
try {
    ktesios_with_requests_lock(static function (): void {
    });
} catch (RuntimeException $error) {
    $lockError = $error->getMessage();
}
check($lockError !== '', 'unopenable lock file is an error');
check(strpos($lockError, $blockedDir . '/requests.json.lock') !== false, 'lock error names the path that was tried');
check(strpos($lockError, 'schrijfbaar') !== false, 'lock error tells you to make the directory writable');
check(is_file($blockedDir . '/requests.json'), 'store is seeded even when the lock cannot be opened');

$parentFile = $tmp . '/not-a-directory';
file_put_contents($parentFile, 'x');
$GLOBALS['ktesios_requests_path'] = $parentFile . '/child/requests.json';
$dirError = '';
try {
    ktesios_with_requests_lock(static function (): void {
    });
} catch (RuntimeException $error) {
    $dirError = $error->getMessage();
}
check(strpos($dirError, $parentFile . '/child') !== false, 'missing data directory error names the path');
check(strpos($dirError, 'schrijfbaar') !== false, 'missing data directory error mentions write access');
unset($GLOBALS['ktesios_requests_seed_path']);

check(strpos((string) file_get_contents(__DIR__ . '/../web/index.php'), 'ktesios_with_requests_lock') !== false, 'worklist holds the lock around reconcile');
check(strpos((string) file_get_contents(__DIR__ . '/../web/request.php'), 'ktesios_with_requests_lock') !== false, 'detail holds the lock around reconcile and approve');

$index = (string) file_get_contents(__DIR__ . '/../web/index.php');
check(strpos($index, 'ktesios_reconcile_requests') !== false, 'worklist reconciles on load');
check(strpos($index, 'getMessage()') !== false, 'worklist shows the storage error text');
$requestPage = (string) file_get_contents(__DIR__ . '/../web/request.php');
check(strpos($requestPage, 'approve-modal') !== false, 'detail page has a confirmation modal');
check(strpos($requestPage, 'stap=bevestigen') !== false, 'detail page has a no-js confirmation step');
check(strpos($requestPage, 'bevestig') !== false, 'approval requires the confirmation field');
$csrfPos = strpos($requestPage, 'ktesios_csrf_valid');
$applyPos = strpos($requestPage, 'ktesios_apply_request_action');
check($csrfPos !== false && $applyPos !== false && $csrfPos < $applyPos, 'CSRF is checked before approve or edit');
check(strpos($requestPage, 'ktesios_can_approve') !== false, 'detail hides approve and edit unless the user may change requests');
check(strpos($requestPage, 'ktesios_requester_hint') !== false, 'non-approvers see why approve and edit are hidden');
$actionFn = strstr($bcSource, 'function ktesios_apply_request_action');
check(is_string($actionFn), 'approve and edit share one server-side handler');
$permPos = is_string($actionFn) ? strpos($actionFn, 'ktesios_can_approve') : false;
$tokenPos = is_string($actionFn) ? strpos($actionFn, '!$csrfOk') : false;
$approveCall = is_string($actionFn) ? strpos($actionFn, 'ktesios_approve_request') : false;
$editCall = is_string($actionFn) ? strpos($actionFn, 'ktesios_edit_open_request') : false;
check($permPos !== false && $tokenPos !== false && $approveCall !== false && $permPos < $tokenPos && $tokenPos < $approveCall, 'approver and CSRF checks run before approve');
check($editCall !== false && $tokenPos !== false && $tokenPos < $editCall, 'approver and CSRF checks run before edit');
$newPage = (string) file_get_contents(__DIR__ . '/../web/new.php');
$newCsrf = strpos($newPage, 'ktesios_csrf_valid');
$newCreate = strpos($newPage, 'ktesios_create_open_request');
check($newCsrf !== false && $newCreate !== false && $newCsrf < $newCreate, 'new request checks CSRF before it is stored');
check(strpos($newPage, 'ktesios_can_approve') === false, 'submitting a request does not require an approver');
$layout = (string) file_get_contents(__DIR__ . '/../web/lib/layout.php');
check(strpos($layout, 'name="csrf"') !== false, 'confirm form posts the CSRF token');
$storeSource = (string) file_get_contents(__DIR__ . '/../web/lib/requests_store.php');
check(strpos($storeSource, "\$path . '.tmp'") === false, 'save does not use one shared temp path');
check(strpos($storeSource, 'tempnam(') !== false, 'save uses a unique tempnam per writer');

$phpFiles = [
    'web/index.php',
    'web/request.php',
    'web/new.php',
    'web/archive.php',
    'web/logincheck.php',
    'web/auth_TEMPLATE.php',
    'web/lib/bc_customer.php',
    'web/lib/requests_store.php',
    'web/lib/mimir_client.php',
    'web/lib/layout.php',
    'web/lib/bootstrap.php',
    'web/lib/html.php',
    'web/lib/csrf.php',
];
foreach ($phpFiles as $relative) {
    $source = (string) file_get_contents(__DIR__ . '/../' . $relative);
    $hasModern = preg_match('/\b(enum|readonly|never)\b/', $source) === 1
        || preg_match('/\)\s*:\s*[A-Za-z_\\\\]+&/', $source) === 1;
    check($hasModern === false, $relative . ' stays on PHP 8.0 syntax');
    if ($relative !== 'web/auth_TEMPLATE.php') {
        check(strpos($source, 'declare(strict_types=1);') !== false, $relative . ' is strict');
    }
}

$realAuthPath = __DIR__ . '/../web/auth.php';
$realAuthBefore = is_file($realAuthPath) ? file_get_contents($realAuthPath) : null;
$scopeWeb = $tmp . '/scope-app/web';
if (!mkdir($scopeWeb . '/lib', 0775, true) && !is_dir($scopeWeb . '/lib')) {
    fwrite(STDERR, "cannot create scope app tree\n");
    exit(1);
}
copy(__DIR__ . '/../web/logincheck.php', $scopeWeb . '/logincheck.php');
foreach (glob(__DIR__ . '/../web/lib/*.php') ?: [] as $libFile) {
    copy($libFile, $scopeWeb . '/lib/' . basename($libFile));
}
file_put_contents($scopeWeb . '/auth.php', "<?php\n\$canWriteToBC = true;\n\$mimirApi = 'mimir_scope_test';\n");
$runner = $tmp . '/scope-runner.php';
file_put_contents(
    $runner,
    "<?php\n\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';\nrequire " . var_export($scopeWeb . '/lib/bootstrap.php', true) . ";\n"
    . "echo ktesios_can_write_to_bc() ? \"write-yes\\n\" : \"write-no\\n\";\n"
    . "echo ktesios_mimir_enabled() ? \"mimir-yes\\n\" : \"mimir-no\\n\";\n"
);
$scopeOut = shell_exec('php ' . escapeshellarg($runner) . ' 2>&1');
$realAuthAfter = is_file($realAuthPath) ? file_get_contents($realAuthPath) : null;
check($realAuthBefore === $realAuthAfter, 'scope test does not write web/auth.php');
check(is_file($scopeWeb . '/auth.php'), 'scope test writes only its temporary auth.php');
check(is_string($scopeOut) && strpos($scopeOut, 'write-yes') !== false, 'temporary auth.php write flag stays global after bootstrap');
check(is_string($scopeOut) && strpos($scopeOut, 'mimir-yes') !== false, 'temporary auth.php Mímir key stays global after bootstrap');
if (!is_string($scopeOut) || strpos($scopeOut, 'write-yes') === false) {
    fwrite(STDERR, "scope runner output:\n" . (string) $scopeOut . "\n");
}

unset($GLOBALS['approvers'], $_SESSION['user']);
check(ktesios_can_approve() === false, 'missing approvers list blocks approve and edit');
$GLOBALS['approvers'] = [];
$_SESSION['user'] = ['email' => 'goedkeurder@kvt.nl'];
check(ktesios_can_approve() === false, 'empty approvers list blocks approve and edit');
$GLOBALS['approvers'] = 'goedkeurder@kvt.nl';
check(ktesios_can_approve() === false, 'a string approvers value is not a list');
$GLOBALS['approvers'] = ['goedkeurder@kvt.nl' => true];
check(ktesios_can_approve() === false, 'approver map keys are not treated as emails');
$GLOBALS['approvers'] = [15, '  Goedkeurder@kvt.nl  '];
check(ktesios_can_approve() === true, 'approver match ignores case, space and non-strings');
unset($_SESSION['user']);
check(ktesios_can_approve() === false, 'no email cannot approve even when the list is filled');

$openForGate = sample_request('KA-2026-010', 'open');
$deniedEdit = ktesios_apply_request_action(
    [$openForGate],
    $openForGate,
    ['actie' => 'wijzigen', 'customer' => ['name' => 'Gewijzigd BV']],
    true
);
check($deniedEdit['saved'] === false, 'non-approver edit is refused on the server');
check(($deniedEdit['requests'][0]['customer']['name'] ?? '') === 'Smit & Zonen B.V.', 'refused edit does not change the name');
check(strpos($deniedEdit['error'], 'goedkeurder') !== false, 'refused edit explains who may change a request');
$deniedApprove = ktesios_apply_request_action(
    [$openForGate],
    $openForGate,
    ['actie' => 'goedkeuren', 'bevestig' => 'ja'],
    true
);
check($deniedApprove['saved'] === false && ($deniedApprove['requests'][0]['status'] ?? '') === 'open', 'non-approver approve is refused on the server');

$_SESSION['user'] = ['email' => 'goedkeurder@kvt.nl'];
$badToken = ktesios_apply_request_action(
    [$openForGate],
    $openForGate,
    ['actie' => 'goedkeuren', 'bevestig' => 'ja'],
    false
);
check($badToken['saved'] === false, 'approver still needs a valid CSRF token');
$edited = ktesios_apply_request_action(
    [$openForGate],
    $openForGate,
    ['actie' => 'wijzigen', 'customer' => ['name' => 'Andere BV'], 'note' => 'na controle'],
    true
);
check($edited['saved'] === true, 'approver can edit an open request');
check(($edited['requests'][0]['customer']['name'] ?? '') === 'Andere BV', 'edit stores the new company name');
check(($edited['requests'][0]['customer']['city'] ?? '') === 'Utrecht', 'omitted fields stay as they were');
check(($edited['requests'][0]['status'] ?? '') === 'open', 'edit does not approve the request');
$approvedByRole = ktesios_apply_request_action(
    [$openForGate],
    $openForGate,
    ['actie' => 'goedkeuren', 'bevestig' => 'ja'],
    true
);
check($approvedByRole['saved'] === true && ($approvedByRole['requests'][0]['status'] ?? '') === 'approved', 'approver can approve');
check(($approvedByRole['requests'][0]['approvedBy'] ?? '') === 'goedkeurder@kvt.nl', 'approval stores the approver email');
$closed = sample_request('KA-2026-010', 'approved');
$closedEdit = ktesios_apply_request_action([$closed], $closed, ['actie' => 'wijzigen', 'customer' => ['name' => 'X']], true);
check($closedEdit['saved'] === false, 'approver cannot edit a request that is not open');

$year = gmdate('Y');
$submitted = ktesios_create_open_request(
    [sample_request('KA-' . $year . '-010', 'open')],
    ['customer' => ['name' => 'Nieuw BV', 'city' => 'Delft'], 'note' => 'offerte'],
    'indiener@kvt.nl'
);
check($submitted['ok'] === true, 'a non-approver path can still build a new request');
check(($submitted['request']['id'] ?? '') === 'KA-' . $year . '-011', 'new request ids increment in the current year');
check(($submitted['request']['status'] ?? '') === 'open', 'a submitted request starts open');
check(($submitted['request']['createdBy'] ?? '') === 'indiener@kvt.nl', 'submitter is stored');
$asApprover = ktesios_create_open_request([], ['customer' => ['name' => 'Ook BV']], 'goedkeurder@kvt.nl');
check($asApprover['ok'] === true && ($asApprover['request']['createdBy'] ?? '') === 'goedkeurder@kvt.nl', 'an approver can also submit a request');
$nameless = ktesios_create_open_request([], ['customer' => ['city' => 'Delft']], 'indiener@kvt.nl');
check($nameless['ok'] === false, 'a request without a company name is refused');
unset($GLOBALS['approvers'], $_SESSION['user']);

ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '1');
ini_set('session.cache_limiter', '');
session_save_path($tmp);
require_once __DIR__ . '/../web/lib/csrf.php';
$csrf = ktesios_csrf_token();
check(preg_match('/\A[a-f0-9]{64}\z/', $csrf) === 1, 'CSRF token is 32 random bytes as hex');
check(ktesios_csrf_valid($csrf) === true, 'matching CSRF token is accepted');
check(ktesios_csrf_valid('00') === false, 'short CSRF token is rejected');
check(ktesios_csrf_valid(str_repeat('a', 64)) === false, 'different CSRF token is rejected');
check(ktesios_csrf_valid('') === false, 'empty CSRF token is rejected');
ktesios_csrf_rotate();
check(ktesios_csrf_valid($csrf) === false, 'rotated CSRF token is no longer accepted');
$fresh = ktesios_csrf_token();
check($fresh !== $csrf && ktesios_csrf_valid($fresh) === true, 'a new CSRF token is issued after rotate');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failed\n");
    exit(1);
}

fwrite(STDOUT, "all passed\n");
exit(0);
