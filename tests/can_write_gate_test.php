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
        $GLOBALS['ktesios_bc_write_log_path'],
        $GLOBALS['ktesios_bc_customers_path']
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

$index = (string) file_get_contents(__DIR__ . '/../web/index.php');
check(strpos($index, 'ktesios_reconcile_requests') !== false, 'worklist reconciles on load');
$requestPage = (string) file_get_contents(__DIR__ . '/../web/request.php');
check(strpos($requestPage, 'approve-modal') !== false, 'detail page has a confirmation modal');
check(strpos($requestPage, 'stap=bevestigen') !== false, 'detail page has a no-js confirmation step');
check(strpos($requestPage, 'bevestig') !== false, 'approval requires the confirmation field');

$phpFiles = [
    'web/index.php',
    'web/request.php',
    'web/archive.php',
    'web/logincheck.php',
    'web/auth_TEMPLATE.php',
    'web/lib/bc_customer.php',
    'web/lib/requests_store.php',
    'web/lib/mimir_client.php',
    'web/lib/layout.php',
    'web/lib/bootstrap.php',
    'web/lib/html.php',
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

$authPath = __DIR__ . '/../web/auth.php';
$authBackup = is_file($authPath) ? file_get_contents($authPath) : null;
$runner = $tmp . '/scope-runner.php';
$bootstrapPath = __DIR__ . '/../web/lib/bootstrap.php';
file_put_contents($authPath, "<?php\n\$canWriteToBC = true;\n\$mimirApi = 'mimir_scope_test';\n");
file_put_contents(
    $runner,
    "<?php\n\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';\nrequire " . var_export($bootstrapPath, true) . ";\n"
    . "echo ktesios_can_write_to_bc() ? \"write-yes\\n\" : \"write-no\\n\";\n"
    . "echo ktesios_mimir_enabled() ? \"mimir-yes\\n\" : \"mimir-no\\n\";\n"
);
$scopeOut = shell_exec('php ' . escapeshellarg($runner) . ' 2>&1');
if ($authBackup === null) {
    @unlink($authPath);
} else {
    file_put_contents($authPath, $authBackup);
}
check(is_string($scopeOut) && strpos($scopeOut, 'write-yes') !== false, 'auth.php write flag stays global after bootstrap');
check(is_string($scopeOut) && strpos($scopeOut, 'mimir-yes') !== false, 'auth.php Mímir key stays global after bootstrap');
if (!is_string($scopeOut) || strpos($scopeOut, 'write-yes') === false) {
    fwrite(STDERR, "scope runner output:\n" . (string) $scopeOut . "\n");
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failed\n");
    exit(1);
}

fwrite(STDOUT, "all passed\n");
exit(0);
