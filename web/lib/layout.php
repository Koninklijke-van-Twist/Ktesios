<?php

declare(strict_types=1);

require_once __DIR__ . '/html.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/avatars.php';

function ktesios_page_open(string $title): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
    }
    $titleEsc = h($title);
    echo <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$titleEsc}</title>
  <link rel="manifest" href="site.webmanifest">
  <link rel="icon" href="thumbnail.png">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
HTML;
    ktesios_render_topbar();
    echo '<main class="wrap">';
    ktesios_render_write_banner();
}

function ktesios_page_close(): void
{
    echo '</main>';
    echo '<footer class="site-foot">Ktesios · klantaanvraag-portaal</footer>';
    echo '</body></html>';
}

function ktesios_render_topbar(): void
{
    echo <<<HTML
<header class="topbar">
  <a class="brand" href="index.php">Ktesios</a>
  <nav>
    <a href="index.php">Aanvragen</a>
    <a href="new.php">Nieuw</a>
    <a href="archive.php">Archief</a>
  </nav>
</header>
HTML;
}

function ktesios_render_write_banner(): void
{
    $enabled = function_exists('ktesios_can_write_to_bc') && ktesios_can_write_to_bc();
    if ($enabled) {
        echo '<div class="banner banner-on" role="status">Schrijven naar Business Central staat aan, maar dit skelet doet geen live OData-POST. Goedkeuren logt een stub en zet de aanvraag op afgerond.</div>';
        return;
    }
    echo '<div class="banner" role="status">Schrijven naar Business Central staat uit. Goedkeuren maakt of wijzigt geen klant. Je ziet daarna de payload die anders geschreven zou worden.</div>';
}

function ktesios_render_setup_page(): void
{
    ktesios_page_open('Ktesios instellen');
    echo '<h1>Auth ontbreekt</h1>';
    echo '<p>Kopieer <code>web/auth_TEMPLATE.php</code> naar <code>web/auth.php</code>. Dat bestand staat in <code>.gitignore</code> en hoort niet in git.</p>';
    echo '<p>Laat <code>$canWriteToBC = false</code> staan tot Ariadne de AppCustomerCard-write heeft ingevuld. Zonder <code>$mimirApi</code> gebruikt het overzicht de sample-fixtures.</p>';
    ktesios_page_close();
}

/**
 * @param array<string, mixed> $request
 */
function ktesios_customer_name(array $request): string
{
    if (!function_exists('ktesios_request_customer')) {
        $customer = $request['customer'] ?? null;
        if (!is_array($customer)) {
            return '';
        }
        return (string) ($customer['name'] ?? '');
    }
    return (string) (ktesios_request_customer($request)['name'] ?? '');
}

/**
 * @param list<array<string, mixed>> $requests
 */
function ktesios_render_request_table(array $requests, string $emptyText): void
{
    if ($requests === []) {
        echo '<p class="empty">' . h($emptyText) . '</p>';
        return;
    }
    echo '<div class="table-wrap"><table class="list">';
    echo '<thead><tr><th>Nummer</th><th>Bedrijf</th><th>Plaats</th><th>Aangemaakt</th><th>Status</th></tr></thead><tbody>';
    foreach ($requests as $request) {
        if (!is_array($request)) {
            continue;
        }
        $customer = function_exists('ktesios_request_customer') ? ktesios_request_customer($request) : [];
        $id = (string) ($request['id'] ?? '');
        $href = 'request.php?id=' . rawurlencode($id);
        $pill = function_exists('ktesios_status_pill') ? ktesios_status_pill($request) : 'open';
        $label = function_exists('ktesios_status_label') ? ktesios_status_label($request) : $id;
        echo '<tr>';
        echo '<td><a href="' . h($href) . '">' . h($id) . '</a></td>';
        $created = ktesios_format_displayed_when((string) ($request['createdAt'] ?? ''));
        echo '<td>' . h((string) ($customer['name'] ?? '')) . '</td>';
        echo '<td>' . h((string) ($customer['city'] ?? '')) . '</td>';
        echo '<td>' . h($created !== '' ? $created : '—') . '</td>';
        echo '<td><span class="pill pill-' . h($pill) . '">' . h($label) . '</span></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

/**
 * @param list<array{field?: string, label?: string, approved?: string, bc?: string}> $diff
 */
function ktesios_render_diff_table(array $diff): void
{
    echo '<div class="table-wrap"><table class="diff">';
    echo '<thead><tr><th>Veld</th><th>Goedgekeurd</th><th>Business Central</th></tr></thead><tbody>';
    foreach ($diff as $row) {
        if (!is_array($row)) {
            continue;
        }
        echo '<tr>';
        echo '<td>' . h((string) ($row['label'] ?? $row['field'] ?? '')) . '</td>';
        echo '<td>' . h((string) ($row['approved'] ?? '')) . '</td>';
        echo '<td>' . h((string) ($row['bc'] ?? '')) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

/**
 * @param array<string, mixed> $payload
 */
function ktesios_render_payload_panel(string $title, string $lead, array $payload, string $modifier): void
{
    $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : [];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo '<section class="panel ' . h($modifier) . '">';
    echo '<h2>' . h($title) . '</h2>';
    echo '<p>' . h($lead) . '</p>';
    if ($fields !== []) {
        echo '<div class="table-wrap"><table class="diff"><thead><tr><th>BC-veld</th><th>Waarde</th></tr></thead><tbody>';
        foreach ($fields as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            echo '<tr><td>' . h($key) . '</td><td>' . h(is_scalar($value) ? (string) $value : '') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    $kvk = trim((string) ($payload['kvkNotMapped'] ?? ''));
    if ($kvk !== '') {
        echo '<p class="hint">KvK-nummer <strong>' . h($kvk) . '</strong> zit niet in de BC-payload. Ariadne koppelt dat veld later.</p>';
    }
    echo '<pre>' . h($json === false ? '' : $json) . '</pre>';
    echo '</section>';
}

/**
 * @param array<string, mixed> $request
 */
function ktesios_render_customer_fields(array $request): void
{
    $customer = function_exists('ktesios_request_customer') ? ktesios_request_customer($request) : [];
    $rows = [
        'Bedrijfsnaam' => (string) ($customer['name'] ?? ''),
        'Contactpersoon' => (string) ($customer['contact'] ?? ''),
        'Adres' => (string) ($customer['address'] ?? ''),
        'Postcode' => (string) ($customer['postCode'] ?? ''),
        'Plaats' => (string) ($customer['city'] ?? ''),
        'Land' => (string) ($customer['country'] ?? ''),
        'Telefoon' => (string) ($customer['phone'] ?? ''),
        'E-mail' => (string) ($customer['email'] ?? ''),
        'BTW-nummer' => (string) ($customer['vat'] ?? ''),
        'KvK-nummer' => (string) ($customer['kvk'] ?? ''),
    ];
    echo '<dl class="fields">';
    foreach ($rows as $label => $value) {
        echo '<div><dt>' . h($label) . '</dt><dd>' . h($value !== '' ? $value : '—') . '</dd></div>';
    }
    $note = trim((string) ($request['note'] ?? ''));
    echo '<div><dt>Opmerking</dt><dd>' . h($note !== '' ? $note : '—') . '</dd></div>';
    echo '</dl>';
}

/**
 * @return array<string, string>
 */
function ktesios_customer_form_labels(): array
{
    return [
        'name' => 'Bedrijfsnaam',
        'contact' => 'Contactpersoon',
        'address' => 'Adres',
        'postCode' => 'Postcode',
        'city' => 'Plaats',
        'country' => 'Land',
        'phone' => 'Telefoon',
        'email' => 'E-mail',
        'vat' => 'BTW-nummer',
        'kvk' => 'KvK-nummer',
    ];
}

/**
 * @param array<string, mixed> $values
 */
function ktesios_render_request_form(array $values, string $actionUrl, string $actie, string $submitLabel): void
{
    $customer = isset($values['customer']) && is_array($values['customer']) ? $values['customer'] : [];
    echo '<form method="post" action="' . h($actionUrl) . '">';
    echo '<input type="hidden" name="csrf" value="' . h(ktesios_csrf_token()) . '">';
    echo '<input type="hidden" name="actie" value="' . h($actie) . '">';
    echo '<div class="form-grid">';
    foreach (ktesios_customer_form_labels() as $key => $label) {
        $class = ($key === 'name' || $key === 'address') ? ' class="wide"' : '';
        echo '<label' . $class . '>' . h($label);
        echo '<input name="customer[' . h($key) . ']" value="' . h((string) ($customer[$key] ?? '')) . '" maxlength="200"';
        if ($key === 'name') {
            echo ' required';
        }
        echo '></label>';
    }
    echo '<label class="wide">Opmerking<textarea name="note" maxlength="1000" rows="3">';
    echo h((string) ($values['note'] ?? ''));
    echo '</textarea></label>';
    echo '</div>';
    echo '<div class="actions"><button class="btn btn-primary" type="submit">' . h($submitLabel) . '</button></div>';
    echo '</form>';
}

function ktesios_confirm_copy(): string
{
    if (function_exists('ktesios_can_write_to_bc') && ktesios_can_write_to_bc()) {
        return 'Je keurt deze aanvraag goed. Het skelet logt een stub-schrijfactie en zet de aanvraag op afgerond. Er gaat geen live OData-POST naar productie.';
    }
    return 'Je keurt deze aanvraag goed. Er wordt geen klant aangemaakt of gewijzigd in Business Central. Daarna zie je de payload die anders geschreven zou worden.';
}

/**
 * @param array<string, mixed> $request
 */
function ktesios_render_confirm_form(array $request): void
{
    $id = (string) ($request['id'] ?? '');
    echo '<form method="post" action="request.php?id=' . h(rawurlencode($id)) . '">';
    echo '<input type="hidden" name="csrf" value="' . h(ktesios_csrf_token()) . '">';
    echo '<input type="hidden" name="actie" value="goedkeuren">';
    echo '<input type="hidden" name="bevestig" value="ja">';
    echo '<div class="actions">';
    echo '<button class="btn btn-primary" type="submit">Ja, goedkeuren</button>';
    echo '<a class="btn" href="request.php?id=' . h(rawurlencode($id)) . '">Annuleren</a>';
    echo '</div></form>';
}

function ktesios_reject_copy(): string
{
    return 'Je keurt deze aanvraag af. De aanvraag gaat naar het archief en kan daarna niet meer worden goedgekeurd. De reden komt in de activiteit te staan.';
}

/**
 * @param array<string, mixed> $request
 */
function ktesios_render_reject_form(array $request, string $draftReason = '', string $fieldId = 'reject-reason'): void
{
    $id = (string) ($request['id'] ?? '');
    echo '<form method="post" action="request.php?id=' . h(rawurlencode($id)) . '">';
    echo '<input type="hidden" name="csrf" value="' . h(ktesios_csrf_token()) . '">';
    echo '<input type="hidden" name="actie" value="afkeuren">';
    echo '<input type="hidden" name="bevestig" value="ja">';
    echo '<label class="reject-reason" for="' . h($fieldId) . '">Reden';
    echo '<textarea id="' . h($fieldId) . '" name="reden" rows="3" maxlength="1000" required>' . h($draftReason) . '</textarea>';
    echo '</label>';
    echo '<p class="hint">Verplicht. Deze tekst komt in de activiteit te staan.</p>';
    echo '<div class="actions">';
    echo '<button class="btn btn-danger" type="submit">Ja, afkeuren</button>';
    echo '<a class="btn" href="request.php?id=' . h(rawurlencode($id)) . '">Annuleren</a>';
    echo '</div></form>';
}

/**
 * Goedkeuren en afkeuren. Niets als de aanvraag gearchiveerd is of niet open.
 *
 * @param array<string, mixed> $request
 */
function ktesios_render_decision_actions(array $request, string $draftReason = ''): void
{
    if (!function_exists('ktesios_may_approve_request') || !ktesios_may_approve_request($request)) {
        return;
    }
    $id = (string) ($request['id'] ?? '');
    $href = 'request.php?id=' . rawurlencode($id);
    echo '<div class="actions">';
    echo '<button class="btn btn-primary" type="button" id="open-approve">Goedkeuren</button>';
    echo '<button class="btn btn-danger" type="button" id="open-reject">Afkeuren</button>';
    echo '</div>';
    echo '<noscript><p class="actions">';
    echo '<a class="btn btn-primary" href="' . h($href) . '&amp;stap=bevestigen">Goedkeuren (bevestigen)</a>';
    echo '<a class="btn btn-danger" href="' . h($href) . '&amp;stap=afkeuren">Afkeuren (reden invullen)</a>';
    echo '</p></noscript>';
    echo '<div class="modal" id="approve-modal" hidden>';
    echo '<div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="approve-title">';
    echo '<h2 id="approve-title">Klantaanvraag goedkeuren?</h2>';
    echo '<p>' . h(ktesios_confirm_copy()) . '</p>';
    ktesios_render_confirm_form($request);
    echo '</div></div>';
    echo '<div class="modal" id="reject-modal" hidden>';
    echo '<div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="reject-title">';
    echo '<h2 id="reject-title">Klantaanvraag afkeuren?</h2>';
    echo '<p>' . h(ktesios_reject_copy()) . '</p>';
    ktesios_render_reject_form($request, $draftReason, 'reject-reason-modal');
    echo '</div></div>';
    echo <<<'JS'
<script>
(function () {
  var pairs = [
    { open: 'open-approve', modal: 'approve-modal', focus: '.btn-primary' },
    { open: 'open-reject', modal: 'reject-modal', focus: 'textarea' }
  ];
  function closeModal(modal, opener) {
    modal.hidden = true;
    if (opener) {
      opener.focus();
    }
  }
  pairs.forEach(function (pair) {
    var openBtn = document.getElementById(pair.open);
    var modal = document.getElementById(pair.modal);
    if (!openBtn || !modal) {
      return;
    }
    function openModal() {
      modal.hidden = false;
      var field = modal.querySelector(pair.focus);
      if (field) {
        field.focus();
      }
    }
    openBtn.addEventListener('click', openModal);
    modal.addEventListener('click', function (event) {
      if (event.target === modal) {
        closeModal(modal, openBtn);
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
        closeModal(modal, openBtn);
      }
    });
  });
})();
</script>
JS;
}

/**
 * @param array<string, mixed> $request
 */
function ktesios_render_request_meta(array $request): void
{
    $approvedAt = ktesios_format_displayed_when((string) ($request['approvedAt'] ?? ''));
    $approvedBy = trim((string) ($request['approvedBy'] ?? ''));
    $approved = $approvedAt;
    if ($approvedBy !== '') {
        $approved = trim($approvedAt . ' ' . $approvedBy);
    }
    $rows = [
        'Aangemaakt' => ktesios_format_displayed_when((string) ($request['createdAt'] ?? '')),
        'Ingediend door' => trim((string) ($request['createdBy'] ?? '')),
        'Goedgekeurd' => $approved,
        'Afgerond' => ktesios_format_displayed_when((string) ($request['archivedAt'] ?? '')),
        'Reden archief' => trim((string) ($request['archiveReason'] ?? '')),
        'BC-klantnummer' => trim((string) ($request['bcCustomerNo'] ?? '')),
    ];
    echo '<dl class="fields meta">';
    foreach ($rows as $label => $value) {
        if ($value === '') {
            continue;
        }
        echo '<div><dt>' . h($label) . '</dt><dd>' . h($value) . '</dd></div>';
    }
    echo '</dl>';
}

function ktesios_dutch_month_name(int $month): string
{
    $names = [
        1 => 'januari',
        2 => 'februari',
        3 => 'maart',
        4 => 'april',
        5 => 'mei',
        6 => 'juni',
        7 => 'juli',
        8 => 'augustus',
        9 => 'september',
        10 => 'oktober',
        11 => 'november',
        12 => 'december',
    ];
    return $names[$month] ?? '';
}

function ktesios_amsterdam_zone(): DateTimeZone
{
    return new DateTimeZone('Europe/Amsterdam');
}

function ktesios_parse_displayed_moment(string $value): ?DateTimeImmutable
{
    try {
        return new DateTimeImmutable($value);
    } catch (Exception $error) {
        return null;
    }
}

function ktesios_format_dutch_date(DateTimeImmutable $when): string
{
    $month = ktesios_dutch_month_name((int) $when->format('n'));
    if ($month === '') {
        return '';
    }
    return $when->format('j') . ' ' . $month . ' ' . $when->format('Y');
}

function ktesios_format_dutch_clock(DateTimeImmutable $when): string
{
    return $when->format('H:i');
}

function ktesios_datetime_has_overflow(): bool
{
    $errors = DateTimeImmutable::getLastErrors();
    if (!is_array($errors)) {
        return false;
    }
    return (int) ($errors['warning_count'] ?? 0) > 0 || (int) ($errors['error_count'] ?? 0) > 0;
}

function ktesios_format_date(string $value): string
{
    $value = trim($value);
    if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1) {
        $when = DateTimeImmutable::createFromFormat('!Y-m-d', $value, ktesios_amsterdam_zone());
        if ($when === false || ktesios_datetime_has_overflow()) {
            return '';
        }
        return ktesios_format_dutch_date($when);
    }
    $when = ktesios_parse_displayed_moment($value);
    if ($when === null) {
        return '';
    }
    return ktesios_format_dutch_date($when->setTimezone(ktesios_amsterdam_zone()));
}

function ktesios_format_time(string $value): string
{
    $value = trim($value);
    if (preg_match('/\A(\d{2}):(\d{2})(?::\d{2})?\z/', $value, $match) === 1) {
        $hour = (int) $match[1];
        $minute = (int) $match[2];
        if ($hour > 23 || $minute > 59) {
            return '';
        }
        return sprintf('%02d:%02d', $hour, $minute);
    }
    $when = ktesios_parse_displayed_moment($value);
    if ($when === null) {
        return '';
    }
    return ktesios_format_dutch_clock($when->setTimezone(ktesios_amsterdam_zone()));
}

function ktesios_format_datetime(string $value): string
{
    $when = ktesios_parse_displayed_moment(trim($value));
    if ($when === null) {
        return '';
    }
    $local = $when->setTimezone(ktesios_amsterdam_zone());
    $date = ktesios_format_dutch_date($local);
    if ($date === '') {
        return '';
    }
    return $date . ', ' . ktesios_format_dutch_clock($local);
}

/**
 * Datum+tijd, alleen een datum, of alleen een tijd. Ruwe ISO komt niet terug.
 */
function ktesios_format_displayed_when(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/\A\d{2}:\d{2}(?::\d{2})?\z/', $value) === 1) {
        return ktesios_format_time($value);
    }
    if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1) {
        return ktesios_format_date($value);
    }
    if (preg_match('/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value) === 1) {
        return ktesios_format_datetime($value);
    }
    return $value;
}

function ktesios_format_activity_time(string $value): string
{
    return ktesios_format_displayed_when($value);
}

function ktesios_activity_initial(string $email): string
{
    $source = trim($email);
    if ($source === '') {
        return '·';
    }
    if (function_exists('mb_substr')) {
        $letter = mb_substr($source, 0, 1, 'UTF-8');
        return function_exists('mb_strtoupper') ? mb_strtoupper($letter, 'UTF-8') : strtoupper($letter);
    }
    return strtoupper(substr($source, 0, 1));
}

function ktesios_render_user_avatar(string $email): void
{
    $colors = ktesios_color_from_text($email);
    $border = (string) $colors['border'];
    $url = ktesios_user_avatar_url($email);
    if ($url === '') {
        echo '<span class="ktesios-user-avatar ktesios-user-avatar--fallback" style="border-color:'
            . h($border)
            . ';background:' . h((string) $colors['chipBackground'])
            . ';color:' . h((string) $colors['chipTextColor'])
            . '">' . h(ktesios_activity_initial($email)) . '</span>';
        return;
    }
    echo '<img class="ktesios-user-avatar" src="' . h($url) . '" width="30" height="30" alt="" style="border-color:'
        . h($border) . '">';
}

/**
 * @param array<string, mixed> $message
 */
function ktesios_render_activity_message(array $message): void
{
    $email = strtolower(trim((string) ($message['email'] ?? '')));
    $colors = ktesios_color_from_text($email);
    $isSystem = (string) ($message['kind'] ?? '') === 'system';
    $actorName = trim((string) ($message['actor_name'] ?? ''));
    if ($actorName !== '') {
        $author = $actorName;
    } elseif ($email !== '') {
        $author = $email;
    } else {
        $author = 'onbekend';
    }
    $when = ktesios_format_activity_time((string) ($message['created_at'] ?? ''));
    echo '<div class="ktesios-message-row">';
    echo '<div class="ktesios-message-avatar-wrap">';
    ktesios_render_user_avatar($email);
    echo '</div>';
    echo '<article class="ktesios-message' . ($isSystem ? ' ktesios-message--system' : '') . '" style="border-color:'
        . h((string) $colors['border']) . ';background:' . h((string) $colors['cardBackground']) . '">';
    echo '<div class="ktesios-message-meta"><span class="ktesios-message-email" style="background:'
        . h((string) $colors['chipBackground']) . ';color:' . h((string) $colors['chipTextColor']) . '">'
        . h($author) . '</span>';
    if ($when !== '') {
        echo '<span>' . h($when) . '</span>';
    }
    echo '</div>';
    echo '<div class="ktesios-message-text' . ($isSystem ? ' ktesios-message-system-text' : '') . '">'
        . h((string) ($message['text'] ?? '')) . '</div>';
    echo '</article></div>';
}

/**
 * @param array<string, mixed> $request
 */
function ktesios_render_activity(array $request, string $draftText = ''): void
{
    $messages = function_exists('ktesios_request_messages') ? ktesios_request_messages($request) : [];
    $id = (string) ($request['id'] ?? '');
    echo '<section class="ktesios-detail-chat" id="activiteit">';
    echo '<h2 class="ktesios-detail-chat-title">Activiteit</h2>';
    echo '<div class="ktesios-messages" id="ktesios-messages">';
    if ($messages === []) {
        echo '<p class="hint ktesios-messages-empty">Nog geen activiteit.</p>';
    }
    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }
        ktesios_render_activity_message($message);
    }
    echo '</div>';
    echo '<form class="ktesios-message-compose" method="post" action="request.php?id=' . h(rawurlencode($id)) . '" id="ktesios-message-form">';
    echo '<input type="hidden" name="csrf" value="' . h(ktesios_csrf_token()) . '">';
    echo '<input type="hidden" name="actie" value="bericht">';
    echo '<label class="ktesios-compose-label" for="ktesios-message-text">Bericht</label>';
    echo '<textarea id="ktesios-message-text" class="ktesios-message-input" name="text" rows="2" maxlength="2000" required>'
        . h($draftText) . '</textarea>';
    echo '<p class="hint">Enter verstuurt. Shift+Enter maakt een nieuwe regel.</p>';
    echo '<div class="actions"><button class="btn btn-primary" type="submit">Versturen</button></div>';
    echo '</form>';
    echo <<<'JS'
<script>
(function () {
  var form = document.getElementById('ktesios-message-form');
  var textarea = document.getElementById('ktesios-message-text');
  var log = document.getElementById('ktesios-messages');
  if (log) {
    log.scrollTop = log.scrollHeight;
  }
  if (!form || !textarea) {
    return;
  }
  function resize() {
    var maxHeight = Math.min(window.innerHeight * 0.32, 280);
    textarea.style.height = 'auto';
    var nextHeight = Math.min(textarea.scrollHeight, maxHeight);
    textarea.style.height = nextHeight + 'px';
    textarea.style.overflowY = textarea.scrollHeight > maxHeight ? 'auto' : 'hidden';
  }
  textarea.addEventListener('input', resize);
  textarea.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      if (textarea.value.replace(/\s/g, '') !== '') {
        form.submit();
      }
    }
  });
  resize();
})();
</script>
JS;
    echo '</section>';
}
