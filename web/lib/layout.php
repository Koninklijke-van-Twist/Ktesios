<?php

declare(strict_types=1);

require_once __DIR__ . '/html.php';
require_once __DIR__ . '/csrf.php';

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
    echo '<p>Laat <code>$canWriteToBC = false</code> staan tot Ariadne de Customer-write heeft ingevuld. Zonder <code>$mimirApi</code> gebruikt het overzicht de sample-fixtures.</p>';
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
    echo '<thead><tr><th>Nummer</th><th>Bedrijf</th><th>Plaats</th><th>Status</th></tr></thead><tbody>';
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
        echo '<td>' . h((string) ($customer['name'] ?? '')) . '</td>';
        echo '<td>' . h((string) ($customer['city'] ?? '')) . '</td>';
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
