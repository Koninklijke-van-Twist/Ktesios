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
        if ($request !== null && (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')) {
            $postedCsrf = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
            $csrfOk = ktesios_csrf_valid($postedCsrf);
            $outcome = ktesios_apply_request_action($requests, $request, $_POST, $csrfOk);
            if ($outcome['rotate'] === true) {
                ktesios_csrf_rotate();
            }
            if ($outcome['error'] !== '') {
                $postError = $outcome['error'];
                if ((string) ($_POST['actie'] ?? '') === 'bericht' && isset($_POST['text']) && is_string($_POST['text'])) {
                    $draftText = $_POST['text'];
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
if (!is_array($request)) {
    http_response_code(404);
    ktesios_page_open('Aanvraag');
    echo '<h1>Aanvraag niet gevonden</h1><p class="hint"><a href="index.php">Terug naar aanvragen</a></p>';
    ktesios_page_close();
    exit;
}

$step = (string) ($_GET['stap'] ?? '');
$isOpen = (string) ($request['status'] ?? '') === 'open';
$mayChange = ktesios_can_approve();
$confirming = $isOpen && $mayChange && $step === 'bevestigen';

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

if ($isOpen && $mayChange && !$confirming) {
    echo '<section class="panel">';
    echo '<h2>Gegevens wijzigen</h2>';
    ktesios_render_request_form($request, 'request.php?id=' . rawurlencode($id), 'wijzigen', 'Wijzigingen opslaan');
    echo '</section>';
} else {
    ktesios_render_customer_fields($request);
}

$meta = [
    'Aangemaakt' => (string) ($request['createdAt'] ?? ''),
    'Ingediend door' => (string) ($request['createdBy'] ?? ''),
    'Goedgekeurd' => trim((string) ($request['approvedAt'] ?? '') . ' ' . (string) ($request['approvedBy'] ?? '')),
    'Afgerond' => (string) ($request['archivedAt'] ?? ''),
    'Reden archief' => (string) ($request['archiveReason'] ?? ''),
    'BC-klantnummer' => (string) ($request['bcCustomerNo'] ?? ''),
];
echo '<dl class="fields meta">';
foreach ($meta as $label => $value) {
    $value = trim($value);
    if ($value === '') {
        continue;
    }
    echo '<div><dt>' . h($label) . '</dt><dd>' . h($value) . '</dd></div>';
}
echo '</dl>';

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

if ($isOpen && $mayChange && !$confirming) {
    echo '<div class="actions">';
    echo '<button class="btn btn-primary" type="button" id="open-approve">Goedkeuren</button>';
    echo '</div>';
    echo '<noscript><p><a class="btn btn-primary" href="request.php?id=' . h(rawurlencode($id)) . '&amp;stap=bevestigen">Goedkeuren (bevestigen)</a></p></noscript>';
    echo '<div class="modal" id="approve-modal" hidden>';
    echo '<div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="approve-title">';
    echo '<h2 id="approve-title">Klantaanvraag goedkeuren?</h2>';
    echo '<p>' . h(ktesios_confirm_copy()) . '</p>';
    ktesios_render_confirm_form($request);
    echo '</div></div>';
    echo <<<'JS'
<script>
(function () {
  var openBtn = document.getElementById('open-approve');
  var modal = document.getElementById('approve-modal');
  if (!openBtn || !modal) {
    return;
  }
  function openModal() {
    modal.hidden = false;
    var primary = modal.querySelector('.btn-primary');
    if (primary) {
      primary.focus();
    }
  }
  function closeModal() {
    modal.hidden = true;
    openBtn.focus();
  }
  openBtn.addEventListener('click', openModal);
  modal.addEventListener('click', function (event) {
    if (event.target === modal) {
      closeModal();
    }
  });
  var links = modal.querySelectorAll('a');
  for (var i = 0; i < links.length; i++) {
    links[i].addEventListener('click', function () {
      modal.hidden = true;
    });
  }
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !modal.hidden) {
      closeModal();
    }
  });
})();
</script>
JS;
}

ktesios_render_activity($request, $draftText);

ktesios_page_close();
