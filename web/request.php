<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

$id = trim((string) ($_GET['id'] ?? ''));
if (!ktesios_valid_request_id($id)) {
    http_response_code(404);
    ktesios_page_open('Aanvraag');
    echo '<h1>Aanvraag niet gevonden</h1><p class="hint"><a href="index.php">Terug naar aanvragen</a></p>';
    ktesios_page_close();
    exit;
}

try {
    $handled = ktesios_with_requests_lock(static function () use ($id): array {
        $requests = ktesios_load_requests();
        $reconciliation = ktesios_reconcile_requests($requests);
        if ($reconciliation['changed']) {
            ktesios_save_requests($reconciliation['requests']);
        }
        $requests = $reconciliation['requests'];
        $request = ktesios_find_request($requests, $id);
        $postError = '';
        $redirect = '';
        $draftText = '';
        $draftReason = '';
        $rejectError = false;
        if ($request !== null && (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')) {
            $postedCsrf = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
            $csrfOk = ktesios_csrf_valid($postedCsrf);
            $outcome = ktesios_apply_request_action($requests, $request, $_POST, $csrfOk);
            if ($outcome['rotate'] === true) {
                ktesios_csrf_rotate();
            }
            if ($outcome['error'] !== '') {
                $postError = $outcome['error'];
                $actie = (string) ($_POST['actie'] ?? '');
                if ($actie === 'bericht' && isset($_POST['text']) && is_string($_POST['text'])) {
                    $draftText = $_POST['text'];
                }
                if ($actie === 'afkeuren' && isset($_POST['reden']) && is_string($_POST['reden'])) {
                    $draftReason = $_POST['reden'];
                    $rejectError = true;
                }
            } elseif ($outcome['saved'] === true) {
                ktesios_save_requests($outcome['requests']);
                $redirect = 'request.php?id=' . rawurlencode($id) . '&gemeld=1';
            }
        }
        return [
            'request' => $request,
            'postError' => $postError,
            'redirect' => $redirect,
            'draftText' => $draftText,
            'draftReason' => $draftReason,
            'rejectError' => $rejectError,
        ];
    });
} catch (Throwable $error) {
    http_response_code(500);
    ktesios_page_open('Aanvraag');
    echo '<h1>Aanvraag niet beschikbaar</h1><p>' . h($error->getMessage()) . '</p>';
    ktesios_page_close();
    exit;
}

if ($handled['redirect'] !== '') {
    header('Location: ' . $handled['redirect'], true, 303);
    exit;
}

$request = $handled['request'];
$postError = $handled['postError'];
$draftText = (string) ($handled['draftText'] ?? '');
$draftReason = (string) ($handled['draftReason'] ?? '');
$rejectError = ($handled['rejectError'] ?? false) === true;
if (!is_array($request)) {
    http_response_code(404);
    ktesios_page_open('Aanvraag');
    echo '<h1>Aanvraag niet gevonden</h1><p class="hint"><a href="index.php">Terug naar aanvragen</a></p>';
    ktesios_page_close();
    exit;
}

$step = (string) ($_GET['stap'] ?? '');
$mayChange = ktesios_can_approve();
$mayDecide = ktesios_may_approve_request($request);
$confirming = $mayDecide && $step === 'bevestigen';
$rejecting = $mayDecide && $step === 'afkeuren';
$showRejectPanel = $rejecting || ($rejectError && $mayDecide);

ktesios_page_open($id . ' — Ktesios');
echo '<p class="hint"><a href="index.php">← Aanvragen</a></p>';
echo '<h1>' . h($id) . '</h1>';
echo '<p><span class="pill pill-' . h(ktesios_status_pill($request)) . '">' . h(ktesios_status_label($request)) . '</span></p>';

if ((string) ($_GET['ingediend'] ?? '') === '1') {
    echo '<p class="saved">Aanvraag ingediend.</p>';
}
if ((string) ($_GET['gemeld'] ?? '') === '1') {
    echo '<p class="saved">Opgeslagen.</p>';
}
if ($postError !== '') {
    echo '<p class="field-error">' . h($postError) . '</p>';
}
if (!$mayChange) {
    echo '<p class="hint">' . h(ktesios_requester_hint()) . '</p>';
}

$note = trim((string) ($request['bcNote'] ?? ''));
if ($note !== '' && (string) ($request['status'] ?? '') !== 'open') {
    echo '<p class="lead">' . h($note) . '</p>';
}

if ($confirming) {
    echo '<section class="panel">';
    echo '<h2>Klantaanvraag goedkeuren?</h2>';
    echo '<p>' . h(ktesios_confirm_copy()) . '</p>';
    ktesios_render_confirm_form($request);
    echo '</section>';
}

if ($showRejectPanel) {
    echo '<section class="panel">';
    echo '<h2>Klantaanvraag afkeuren?</h2>';
    echo '<p>' . h(ktesios_reject_copy()) . '</p>';
    ktesios_render_reject_form($request, $draftReason, 'reject-reason-panel');
    echo '</section>';
}

if ($mayDecide && !$confirming && !$rejecting) {
    echo '<section class="panel">';
    echo '<h2>Gegevens wijzigen</h2>';
    ktesios_render_request_form($request, 'request.php?id=' . rawurlencode($id), 'wijzigen', 'Wijzigingen opslaan');
    echo '</section>';
} else {
    ktesios_render_customer_fields($request);
}

ktesios_render_request_meta($request);

$diff = isset($request['bcDiff']) && is_array($request['bcDiff']) ? $request['bcDiff'] : [];
if ($diff !== [] && (string) ($request['bcSync'] ?? '') === 'differs') {
    echo '<section class="panel panel-warn">';
    echo '<h2>Business Central wijkt af</h2>';
    echo '<p>Er is niets overschreven. De goedgekeurde aanvraag blijft wachten.</p>';
    ktesios_render_diff_table($diff);
    echo '</section>';
}

$payload = isset($request['dryRunPayload']) && is_array($request['dryRunPayload']) ? $request['dryRunPayload'] : [];
// bcSync kan na de pagina-loadcontrole waiting of differs zijn. De payload blijft
// staan zolang de aanvraag goedgekeurd is en er niet live geschreven is.
if ($payload !== [] && (string) ($request['status'] ?? '') === 'approved') {
    ktesios_render_payload_panel(
        'Voorbeeld — niet geschreven naar Business Central',
        'Schrijven staat uit. Dit is de payload die een latere AppCustomerCard-insert zou meesturen.',
        $payload,
        'panel-dry'
    );
    $intended = ktesios_bc_intended_request($payload);
    $intendedJson = json_encode($intended, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo '<section class="panel panel-dry">';
    echo '<h2>Bedoelde OData-aanroep (niet verstuurd)</h2>';
    echo '<pre>' . h($intendedJson === false ? '' : $intendedJson) . '</pre>';
    echo '</section>';
}

if ((string) ($request['bcSync'] ?? '') === 'stub-archived' && $payload !== []) {
    ktesios_render_payload_panel(
        'Stub-schrijfactie — geen live POST',
        'De aanvraag is afgerond. Alleen web/data/bc-write.log is bijgewerkt. Productie is niet aangeroepen.',
        $payload,
        'panel-stub'
    );
}

if ($mayDecide && !$confirming && !$rejecting) {
    ktesios_render_decision_actions($request, $draftReason);
}

ktesios_render_activity($request, $draftText);

ktesios_page_close();
