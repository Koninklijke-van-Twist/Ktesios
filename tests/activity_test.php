<?php

/**
 * Activiteit per aanvraag, vrij bericht en procedurele avatars.
 * Geen netwerk en geen web/auth.php.
 *
 *   php tests/activity_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../web/lib/bc_customer.php';
require_once __DIR__ . '/../web/lib/requests_store.php';
require_once __DIR__ . '/../web/lib/avatars.php';

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

/**
 * @param array<string, mixed> $request
 * @return list<array<string, mixed>>
 */
function messages_of(array $request): array
{
    return ktesios_request_messages($request);
}

$tmp = sys_get_temp_dir() . '/ktesios-activity-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "cannot create temp dir\n");
    exit(1);
}

ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '1');
ini_set('session.cache_limiter', '');
session_save_path($tmp);
session_start();

$GLOBALS['ktesios_user_avatar_dir'] = $tmp . '/user_avatars';
$GLOBALS['ktesios_requests_path'] = $tmp . '/requests.json';
$GLOBALS['ktesios_bc_customers_path'] = __DIR__ . '/../web/fixtures/bc_customers.json';
$GLOBALS['canWriteToBC'] = false;
unset($GLOBALS['approvers']);
$_SESSION['user'] = ['email' => 'indiener@kvt.nl', 'name' => 'Iris Indiener'];

$year = gmdate('Y');
$created = ktesios_create_open_request(
    [],
    ['customer' => ['name' => 'Nieuw BV', 'city' => 'Delft'], 'note' => 'offerte'],
    ktesios_actor(),
    ktesios_actor_name()
);
check($created['ok'] === true, 'create still succeeds');
$createdRequest = $created['request'];
$createdMessages = messages_of($createdRequest);
check(count($createdMessages) === 1, 'create appends one activity line');
check(($createdMessages[0]['id'] ?? 0) === 1, 'first message id is 1');
check(($createdMessages[0]['kind'] ?? '') === 'system', 'create line is a system message');
check(($createdMessages[0]['email'] ?? '') === 'indiener@kvt.nl', 'create line uses the actor email');
check(($createdMessages[0]['actor_name'] ?? '') === 'Iris Indiener', 'create line keeps the session display name');
check(
    strpos((string) ($createdMessages[0]['text'] ?? ''), 'Aanvraag ' . ($createdRequest['id'] ?? '') . ' aangemaakt voor Nieuw BV.') !== false,
    'create line names the request id and company'
);
check(preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', (string) ($createdMessages[0]['created_at'] ?? '')) === 1, 'message time is UTC ISO-8601');
$dotted = ktesios_create_open_request([], ['customer' => ['name' => 'Noordkaap B.V.']], 'indiener@kvt.nl', 'Iris Indiener');
$dottedText = (string) (messages_of($dotted['request'])[0]['text'] ?? '');
check(strpos($dottedText, '..') === false && substr($dottedText, -4) === 'B.V.', 'a company name that already ends with a period stays one sentence');
check(messages_of($created['requests'][0] ?? []) === $createdMessages, 'the stored list contains the same create line');

$legacy = sample_request('KA-' . $year . '-010', 'open');
check(array_key_exists('messages', $legacy) === false, 'fixture rows can omit messages');
check(messages_of($legacy) === [], 'missing messages read as an empty list');
$edited = ktesios_edit_open_request(
    $legacy,
    ['customer' => ['name' => 'Andere BV'], 'note' => "na controle\nregel 2"],
    'goedkeurder@kvt.nl',
    'Guus Goedkeurder'
);
check($edited['ok'] === true, 'edit still succeeds');
$editMessages = messages_of($edited['request']);
check(count($editMessages) === 1, 'edit appends one system line');
check(($editMessages[0]['kind'] ?? '') === 'system', 'edit line is a system message');
check(($editMessages[0]['email'] ?? '') === 'goedkeurder@kvt.nl', 'edit line uses the acting user');
check(($editMessages[0]['actor_name'] ?? '') === 'Guus Goedkeurder', 'edit line keeps the display name');
$editText = (string) ($editMessages[0]['text'] ?? '');
check(strpos($editText, 'Velden gewijzigd:') === 0, 'edit line says which fields changed');
check(strpos($editText, 'Bedrijfsnaam (Smit & Zonen B.V. → Andere BV)') !== false, 'edit line shows the company before and after');
check(strpos($editText, 'Opmerking (— → na controle regel 2)') !== false, 'edit line shows the note before and after');
check(strpos($editText, 'Plaats') === false, 'unchanged city is not listed');
$same = ktesios_edit_open_request($edited['request'], ['customer' => ['name' => 'Andere BV'], 'note' => "na controle\nregel 2"], 'goedkeurder@kvt.nl', 'Guus Goedkeurder');
check(count(messages_of($same['request'])) === 1, 'saving without a real change does not add another line');

$again = ktesios_add_user_message($legacy, 'lezer@kvt.nl', 'Eerste', 'Lezer');
$again = ktesios_add_user_message($again['request'], 'lezer@kvt.nl', 'Tweede', '');
$ids = [];
foreach (messages_of($again['request']) as $message) {
    $ids[] = (int) ($message['id'] ?? 0);
}
check($ids === [1, 2], 'message ids increment inside one request');
$withGap = $again['request'];
$withGap['messages'][0]['id'] = 7;
$afterGap = ktesios_add_user_message($withGap, 'lezer@kvt.nl', 'Derde', '');
$last = messages_of($afterGap['request']);
check((int) ($last[count($last) - 1]['id'] ?? 0) === 8, 'next message id is one above the highest existing id');

$long = str_repeat('é', 2500);
$clipped = ktesios_add_user_message(sample_request('KA-2026-010'), 'lezer@kvt.nl', $long, '');
$clippedText = (string) (messages_of($clipped['request'])[0]['text'] ?? '');
$clippedLength = function_exists('mb_strlen') ? mb_strlen($clippedText, 'UTF-8') : strlen($clippedText);
check($clipped['ok'] === true && $clippedLength === 2000, 'user text is clipped to 2000 characters');
$blank = ktesios_add_user_message(sample_request('KA-2026-010'), 'lezer@kvt.nl', "  \n\t", '');
check($blank['ok'] === false && messages_of($blank['request']) === [], 'blank chat text is refused');

unset($GLOBALS['approvers'], $_SESSION['user']);
$_SESSION['user'] = ['email' => 'lezer@kvt.nl', 'displayName' => 'Lezer Een'];
$open = sample_request('KA-2026-010', 'open');
$rejected = ktesios_apply_request_action(
    [$open],
    $open,
    ['actie' => 'bericht', 'text' => 'dit mag niet landen'],
    false
);
check($rejected['saved'] === false, 'chat without a CSRF token is refused');
check(messages_of($rejected['requests'][0]) === [], 'refused chat does not append a message');
check(strpos($rejected['error'], 'sessie') !== false, 'CSRF refusal tells the user to reload');
$posted = ktesios_apply_request_action(
    [$open],
    $open,
    ['actie' => 'bericht', 'text' => "Hoi\nvan de lezer"],
    true
);
check($posted['saved'] === true, 'a viewer who is not an approver can post');
$chat = messages_of($posted['requests'][0]);
check(count($chat) === 1 && ($chat[0]['kind'] ?? '') === 'user', 'free-form text is a user message');
check(($chat[0]['email'] ?? '') === 'lezer@kvt.nl', 'user message stores the session email');
check(($chat[0]['actor_name'] ?? '') === 'Lezer Een', 'user message stores the session display name');
check(($chat[0]['text'] ?? '') === "Hoi\nvan de lezer", 'user message keeps newlines');

$GLOBALS['approvers'] = ['goedkeurder@kvt.nl'];
$_SESSION['user'] = ['email' => 'lezer@kvt.nl', 'Naam' => 'Lezer Naam'];
$deniedEdit = ktesios_apply_request_action(
    [$open],
    $open,
    ['actie' => 'wijzigen', 'customer' => ['name' => 'Gewijzigd BV']],
    true
);
check($deniedEdit['saved'] === false, 'chat access does not grant edit access');

$_SESSION['user'] = ['email' => 'goedkeurder@kvt.nl', 'name' => 'Guus Goedkeurder'];
$approved = ktesios_approve_request(sample_request('KA-2026-011', 'open'), ktesios_actor(), ktesios_actor_name());
$approvalMessages = messages_of($approved['request']);
check(count($approvalMessages) === 1 && ($approvalMessages[0]['kind'] ?? '') === 'system', 'approval writes one system line');
check(strpos((string) ($approvalMessages[0]['text'] ?? ''), 'Goedgekeurd') !== false, 'approval line says it was approved');
check(strpos((string) ($approvalMessages[0]['text'] ?? ''), 'Niets naar Business Central geschreven') !== false, 'approval line summarizes the BC result');
check(strpos((string) ($approvalMessages[0]['text'] ?? ''), '..') === false, 'approval line does not stack periods');
check(($approvalMessages[0]['email'] ?? '') === 'goedkeurder@kvt.nl', 'approval line uses the approver');

$GLOBALS['canWriteToBC'] = true;
$stub = ktesios_approve_request(sample_request('KA-2026-012', 'open'), 'goedkeurder@kvt.nl', 'Guus Goedkeurder');
$stubText = (string) (messages_of($stub['request'])[0]['text'] ?? '');
check(strpos($stubText, 'Goedgekeurd en gearchiveerd') !== false, 'stub approval mentions the archive');
check(strpos($stubText, 'Reden:') !== false, 'stub approval includes the archive reason');
$GLOBALS['canWriteToBC'] = false;

$openReject = sample_request('KA-2026-013', 'open');
check(ktesios_may_approve_request($openReject) === true, 'an approver may approve an open request');
$blankReject = ktesios_reject_request($openReject, ktesios_actor(), "  \n", ktesios_actor_name());
check($blankReject['ok'] === false && ($blankReject['request']['status'] ?? '') === 'open', 'reject without a reason does not archive');
check(messages_of($blankReject['request']) === [], 'a blank rejection does not post activity');
$rejected = ktesios_reject_request($openReject, ktesios_actor(), "Niet de juiste klant.\nBel later.", ktesios_actor_name());
check($rejected['ok'] === true, 'reject with a reason succeeds');
check(($rejected['request']['status'] ?? '') === 'archived', 'reject archives the request');
check(($rejected['request']['bcSync'] ?? '') === 'rejected', 'reject uses the archive path and marks the rejection');
check(($rejected['request']['archiveReason'] ?? '') === 'Niet de juiste klant. Bel later.', 'reject stores the reason on one line');
check((string) ($rejected['request']['archivedAt'] ?? '') !== '', 'reject sets an archive timestamp');
$rejectMessages = messages_of($rejected['request']);
check(count($rejectMessages) === 1 && ($rejectMessages[0]['kind'] ?? '') === 'system', 'reject writes one system line');
check(($rejectMessages[0]['email'] ?? '') === 'goedkeurder@kvt.nl', 'reject line uses the approver');
check(($rejectMessages[0]['actor_name'] ?? '') === 'Guus Goedkeurder', 'reject line keeps the display name');
$rejectText = (string) ($rejectMessages[0]['text'] ?? '');
check($rejectText === 'Afgekeurd. Reden: Niet de juiste klant. Bel later.', 'reject line explains the reason');
check(strpos($rejectText, '..') === false, 'reject line does not stack periods');
check(ktesios_status_label($rejected['request']) === 'Afgekeurd', 'a rejected request is labelled Afgekeurd');
check(ktesios_may_approve_request($rejected['request']) === false, 'approve is unavailable after reject');
$approveAfterReject = ktesios_approve_request($rejected['request'], ktesios_actor(), ktesios_actor_name());
check($approveAfterReject['ok'] === false, 'an afgekeurde aanvraag cannot be approved');
check(($approveAfterReject['request']['status'] ?? '') === 'archived', 'refused approve leaves the rejection archived');
$matchedArchive = sample_request('KA-2026-014', 'archived');
$matchedArchive['bcSync'] = 'matched';
check(ktesios_may_approve_request($matchedArchive) === false, 'approve is unavailable after archive generally');
$approveMatched = ktesios_approve_request($matchedArchive, ktesios_actor(), ktesios_actor_name());
check($approveMatched['ok'] === false && strpos($approveMatched['error'], 'gearchiveerd') !== false, 'any archive blocks a later approval');
$viaAction = ktesios_apply_request_action(
    [sample_request('KA-2026-015', 'open')],
    sample_request('KA-2026-015', 'open'),
    ['actie' => 'afkeuren', 'bevestig' => 'ja', 'reden' => 'Geen KvK.'],
    true
);
check($viaAction['saved'] === true && ($viaAction['requests'][0]['status'] ?? '') === 'archived', 'approver can reject through the action handler');
check((string) (messages_of($viaAction['requests'][0])[0]['text'] ?? '') === 'Afgekeurd. Reden: Geen KvK.', 'action handler posts the rejection reason');
$noToken = ktesios_apply_request_action(
    [sample_request('KA-2026-016', 'open')],
    sample_request('KA-2026-016', 'open'),
    ['actie' => 'afkeuren', 'bevestig' => 'ja', 'reden' => 'Geen KvK'],
    false
);
check($noToken['saved'] === false && ($noToken['requests'][0]['status'] ?? '') === 'open', 'reject without CSRF does not archive');
check(messages_of($noToken['requests'][0]) === [], 'reject without CSRF does not post activity');
$_SESSION['user'] = ['email' => 'lezer@kvt.nl', 'name' => 'Lezer Een'];
$deniedReject = ktesios_apply_request_action(
    [sample_request('KA-2026-017', 'open')],
    sample_request('KA-2026-017', 'open'),
    ['actie' => 'afkeuren', 'bevestig' => 'ja', 'reden' => 'Mag niet'],
    true
);
check($deniedReject['saved'] === false && ($deniedReject['requests'][0]['status'] ?? '') === 'open', 'a viewer cannot reject');
$_SESSION['user'] = ['email' => 'goedkeurder@kvt.nl', 'name' => 'Guus Goedkeurder'];
$waiting = sample_request('KA-2026-018', 'approved');
$rejectWaiting = ktesios_reject_request($waiting, ktesios_actor(), 'Te laat', ktesios_actor_name());
check($rejectWaiting['ok'] === false && ($rejectWaiting['request']['status'] ?? '') === 'approved', 'only an open request can be rejected');

$_SESSION['user'] = ['email' => 'kijker@kvt.nl', 'display_name' => 'Kim Kijker'];
$GLOBALS['ktesios_bc_customers_path'] = __DIR__ . '/../web/fixtures/bc_customers.json';
$seed = json_decode((string) file_get_contents(__DIR__ . '/fixtures/requests_seed.json'), true);
check(is_array($seed), 'activity test can read the reconciliation fixture');
$first = ktesios_reconcile_requests($seed);
$byId = [];
foreach ($first['requests'] as $row) {
    $byId[(string) $row['id']] = $row;
}
check(messages_of($byId['KA-2026-010']) === [], 'an open request is not given a sync line');
check(array_key_exists('messages', $byId['KA-2026-050']) === false, 'an already archived row stays without a messages key');
$archivedLine = (string) (messages_of($byId['KA-2026-020'])[0]['text'] ?? '');
check(count(messages_of($byId['KA-2026-020'])) === 1, 'matching BC customer adds one archive line');
check(strpos($archivedLine, 'Gearchiveerd') !== false, 'archive line says the request was archived');
check(strpos($archivedLine, 'K00042') !== false, 'archive line includes the BC customer number');
check(strpos($archivedLine, 'Reden:') !== false, 'archive line includes the reason');
check(strpos($archivedLine, '..') === false, 'archive line does not stack periods');
check((messages_of($byId['KA-2026-020'])[0]['email'] ?? '') === 'kijker@kvt.nl', 'automatic lines use the user who triggered them');
check((messages_of($byId['KA-2026-020'])[0]['actor_name'] ?? '') === 'Kim Kijker', 'automatic lines keep that user display name');
$diffLine = (string) (messages_of($byId['KA-2026-030'])[0]['text'] ?? '');
check(strpos($diffLine, 'BC-sync:') !== false && strpos($diffLine, 'K00077') !== false, 'a BC difference logs the sync result and customer number');
check(strpos($diffLine, '..') === false, 'sync line does not stack periods');
$second = ktesios_reconcile_requests($first['requests']);
$secondById = [];
foreach ($second['requests'] as $row) {
    $secondById[(string) $row['id']] = $row;
}
check($second['changed'] === false, 'a second reconciliation does not rewrite the file');
check(count(messages_of($secondById['KA-2026-020'])) === 1, 'a stable reconciliation does not duplicate the archive line');
check(count(messages_of($secondById['KA-2026-030'])) === 1, 'a stable reconciliation does not duplicate the diff line');

$storePath = $tmp . '/legacy-store.json';
file_put_contents($storePath, json_encode([sample_request('KA-2026-010', 'open')], JSON_UNESCAPED_UNICODE) . "\n");
$GLOBALS['ktesios_requests_path'] = $storePath;
$loadedBefore = ktesios_load_requests();
check(array_key_exists('messages', $loadedBefore[0]) === false, 'loading an old row does not invent messages');
$saved = ktesios_apply_request_action(
    $loadedBefore,
    $loadedBefore[0],
    ['actie' => 'bericht', 'text' => 'bewaard'],
    true
);
ktesios_save_requests($saved['requests']);
$loadedAfter = ktesios_load_requests();
check(($loadedAfter[0]['customer']['name'] ?? '') === 'Smit & Zonen B.V.', 'adding a message leaves the customer fields alone');
check((messages_of($loadedAfter[0])[0]['text'] ?? '') === 'bewaard', 'a message survives a JSON roundtrip');
check((messages_of($loadedAfter[0])[0]['id'] ?? 0) === 1, 'a first message on an old row starts at id 1');

$emptyColors = ktesios_color_from_text('');
check($emptyColors['border'] === '#cbd5e1' && $emptyColors['dark'] === '#64748b', 'empty text uses the neutral colors');
$one = ktesios_color_from_text('Anna@KVT.nl');
$two = ktesios_color_from_text('anna@kvt.nl');
$other = ktesios_color_from_text('bert@kvt.nl');
check($one === $two, 'colors are deterministic and ignore case');
check($one !== $other, 'different emails get different colors');
check(isset($one['chipBackground'], $one['cardBackground'], $one['chipTextColor']), 'color helper returns chip and card colors');
$rgb = ktesios_color_dark_rgb($one);
check(count($rgb) === 3 && $rgb[0] >= 0 && $rgb[0] <= 255 && $rgb[1] >= 0 && $rgb[1] <= 255 && $rgb[2] >= 0 && $rgb[2] <= 255, 'dark color converts to an RGB triple');
check(ktesios_color_dark_rgb(['dark' => '#64748b']) === [100, 116, 139], 'a non-hsl dark color falls back to the neutral RGB');

$normalPath = ktesios_user_avatar_path('Anna@KVT.nl');
check(ktesios_user_avatar_path_is_contained($normalPath), 'a normal email stays inside the avatar directory');
check(substr($normalPath, -strlen('anna_kvt.nl.png')) === 'anna_kvt.nl.png', 'avatar filename is the sanitized email');
$evilInputs = [
    '../../etc/passwd',
    "foo@bar.com\0/../../etc/passwd",
    '..\\..\\windows\\system32',
    'a/../../b@x.nl',
];
foreach ($evilInputs as $evilInput) {
    $evilPath = ktesios_user_avatar_path($evilInput);
    $contained = $evilPath === '' || ktesios_user_avatar_path_is_contained($evilPath);
    check($contained, 'avatar path stays contained for ' . json_encode($evilInput));
    check($evilPath === '' || strpos($evilPath, '..') === false, 'avatar filename drops parent segments for ' . json_encode($evilInput));
    if ($evilPath !== '') {
        $prefix = rtrim($GLOBALS['ktesios_user_avatar_dir'], "/\\") . DIRECTORY_SEPARATOR;
        check(strpos($evilPath, $prefix) === 0, 'avatar path does not leave the avatar directory for ' . json_encode($evilInput));
    }
}
check(ktesios_user_avatar_url('niet-een-email') === '', 'avatar URL is empty when the email is invalid');
check(ktesios_user_avatar_url('') === '', 'avatar URL is empty without an email');
check(ktesios_generate_user_avatar_png('../etc/passwd') === false, 'generation refuses a value that is not an email');

if (function_exists('imagecreatetruecolor')) {
    check(ktesios_ensure_user_avatar('anna@kvt.nl') === true, 'a valid email generates a PNG');
    $pngPath = ktesios_user_avatar_path('anna@kvt.nl');
    $png = (string) file_get_contents($pngPath);
    check(substr($png, 0, 8) === "\x89PNG\r\n\x1a\n", 'generated avatar is a PNG');
    check(ktesios_avatar_file_is_safe($pngPath) === true, 'generated file passes the path guard');
    $size = getimagesize($pngPath);
    check(is_array($size) && ($size[0] ?? 0) === 30 && ($size[1] ?? 0) === 30, 'avatar is 30 by 30');
    $againPng = (string) file_get_contents($pngPath);
    check(ktesios_ensure_user_avatar('anna@kvt.nl') === true && $againPng === $png, 'generating the same email again keeps the file');
    check(ktesios_ensure_user_avatar('bert@kvt.nl') === true, 'a second email also generates');
    check(sha1_file(ktesios_user_avatar_path('bert@kvt.nl')) !== sha1($png), 'different emails do not share one picture');
    $url = ktesios_user_avatar_url('anna@kvt.nl');
    check($url === 'user_avatar.php?email=' . rawurlencode('anna@kvt.nl'), 'avatar URL is the email query on user_avatar.php');
} else {
    fwrite(STDOUT, "SKIP avatar PNG generation (gd missing)\n");
}

$avatarPage = (string) file_get_contents(__DIR__ . '/../web/user_avatar.php');
check(strpos($avatarPage, 'FILTER_VALIDATE_EMAIL') !== false, 'avatar endpoint validates the email');
check(strpos($avatarPage, 'ktesios_avatar_file_is_safe') !== false, 'avatar endpoint refuses a path outside the avatar directory');
check(strpos($avatarPage, 'image/png') !== false, 'avatar endpoint serves a PNG');

require_once __DIR__ . '/../web/lib/layout.php';
$_SESSION['user'] = ['email' => 'lezer@kvt.nl', 'name' => 'Lezer Een'];
$renderRequest = sample_request('KA-2026-010', 'approved');
$renderRequest['messages'] = [[
    'id' => 1,
    'email' => 'lezer@kvt.nl',
    'text' => "<script>alert(1)</script>\nregel",
    'kind' => 'system',
    'created_at' => '2026-09-29T10:00:00Z',
    'actor_name' => 'Lezer Een',
]];
ob_start();
ktesios_render_activity($renderRequest);
$html = (string) ob_get_clean();
check(strpos($html, 'Activiteit') !== false, 'activity panel title is Dutch');
check(strpos($html, '>Bericht<') !== false, 'compose label is Bericht');
check(strpos($html, '>Versturen<') !== false, 'send button is Versturen');
check(strpos($html, 'name="actie" value="bericht"') !== false, 'compose posts the bericht action');
check(strpos($html, 'name="csrf"') !== false, 'compose posts the CSRF token');
check(strpos($html, 'maxlength="2000"') !== false, 'compose limits the textarea');
check(strpos($html, 'ktesios-message--system') !== false, 'system lines get the italic marker class');
check(strpos($html, 'Lezer Een') !== false, 'the author chip prefers the display name');
check(strpos($html, '29 september 2026, 12:00') !== false, 'timestamp is shown in Dutch Amsterdam time');
check(strpos($html, '2026-09-29T10:00:00Z') === false, 'activity feed does not show the raw ISO timestamp');
check(strpos($html, '<script>alert(1)</script>') === false, 'message text is not raw HTML');
check(strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, 'message text is escaped');
if (function_exists('imagecreatetruecolor')) {
    check(strpos($html, 'user_avatar.php?email=lezer%40kvt.nl') !== false, 'message row points at the avatar endpoint');
    check(strpos($html, 'ktesios-user-avatar') !== false, 'message row renders an avatar');
}

$requestPage = (string) file_get_contents(__DIR__ . '/../web/request.php');
check(strpos($requestPage, 'ktesios_render_activity') !== false, 'the detail page renders the activity panel');
$newPage = (string) file_get_contents(__DIR__ . '/../web/new.php');
check(strpos($newPage, 'ktesios_actor_name()') !== false, 'a new request records the submitter display name');
$css = (string) file_get_contents(__DIR__ . '/../web/assets/app.css');
check(strpos($css, '.ktesios-message-row') !== false && strpos($css, '.ktesios-user-avatar') !== false, 'chat styles use the ktesios prefix');
check(strpos($css, '.ktesios-message--system .ktesios-message-system-text') !== false, 'system text is marked italic in CSS');

check(ktesios_format_displayed_when('2026-09-29T11:57:42+00:00') === '29 september 2026, 13:57', 'offset ISO becomes a Dutch datetime in Amsterdam');
check(ktesios_format_displayed_when('2026-09-29T13:50:00Z') === '29 september 2026, 15:50', 'summer UTC becomes CEST');
check(ktesios_format_displayed_when('2026-01-15T14:50:00+00:00') === '15 januari 2026, 15:50', 'winter UTC becomes CET');
check(ktesios_format_displayed_when('2026-09-29') === '29 september 2026', 'date only is a Dutch date');
check(ktesios_format_displayed_when('2026-01-01') === '1 januari 2026', 'a date-only value does not shift to the previous day');
check(ktesios_format_displayed_when('15:50') === '15:50', 'time only stays HH:mm');
check(ktesios_format_displayed_when('09:05:33') === '09:05', 'time with seconds drops the seconds');
check(ktesios_format_displayed_when('2026-13-40T00:00:00Z') === '', 'an impossible timestamp is not shown as ISO');
check(ktesios_format_activity_time('2026-09-29T10:00:00Z') === '29 september 2026, 12:00', 'activity helper uses the Dutch datetime format');

$metaRequest = sample_request('KA-2026-010', 'approved');
$metaRequest['createdAt'] = '2026-09-29T11:57:42+00:00';
$metaRequest['approvedAt'] = '2026-09-29T13:50:00Z';
$metaRequest['approvedBy'] = 'goedkeurder@kvt.nl';
$metaRequest['archivedAt'] = '2026-01-15T14:50:00+00:00';
ob_start();
ktesios_render_request_meta($metaRequest);
$metaHtml = (string) ob_get_clean();
check(strpos($metaHtml, '29 september 2026, 13:57') !== false, 'detail meta formats the created timestamp');
check(strpos($metaHtml, '29 september 2026, 15:50 goedkeurder@kvt.nl') !== false, 'detail meta formats approval time and keeps the approver');
check(strpos($metaHtml, '15 januari 2026, 15:50') !== false, 'detail meta formats the archive timestamp');
check(strpos($metaHtml, '2026-09-29T') === false, 'detail meta does not show raw ISO');
ob_start();
ktesios_render_request_table([$metaRequest], 'leeg');
$tableHtml = (string) ob_get_clean();
check(strpos($tableHtml, '29 september 2026, 13:57') !== false, 'request list shows a Dutch created timestamp');
check(strpos($tableHtml, '2026-09-29T') === false, 'request list does not show raw ISO');

$GLOBALS['approvers'] = ['goedkeurder@kvt.nl'];
$_SESSION['user'] = ['email' => 'goedkeurder@kvt.nl', 'name' => 'Guus Goedkeurder'];
ob_start();
ktesios_render_decision_actions(sample_request('KA-2026-010', 'open'));
$decisionHtml = (string) ob_get_clean();
check(strpos($decisionHtml, '>Goedkeuren<') !== false, 'open request shows Goedkeuren');
check(strpos($decisionHtml, '>Afkeuren<') !== false, 'open request shows Afkeuren');
check(strpos($decisionHtml, 'name="reden"') !== false && strpos($decisionHtml, 'required') !== false, 'reject form requires a reason');
check(strpos($decisionHtml, 'name="actie" value="afkeuren"') !== false, 'reject form posts afkeuren');
check(strpos($decisionHtml, 'name="csrf"') !== false, 'reject form posts the CSRF token');
$archivedUi = sample_request('KA-2026-010', 'archived');
$archivedUi['bcSync'] = 'matched';
ob_start();
ktesios_render_decision_actions($archivedUi);
check((string) ob_get_clean() === '', 'approve and reject are not rendered after archive');
$rejectedUi = sample_request('KA-2026-010', 'archived');
$rejectedUi['bcSync'] = 'rejected';
ob_start();
ktesios_render_decision_actions($rejectedUi);
check((string) ob_get_clean() === '', 'approve and reject are not rendered after reject');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failed\n");
    exit(1);
}

fwrite(STDOUT, "all passed\n");
exit(0);
