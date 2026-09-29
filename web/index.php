<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

try {
    $reconciliation = ktesios_with_requests_lock(static function (): array {
        $requests = ktesios_load_requests();
        $reconciliation = ktesios_reconcile_requests($requests);
        if ($reconciliation['changed']) {
            ktesios_save_requests($reconciliation['requests']);
        }
        return $reconciliation;
    });
} catch (Throwable $error) {
    http_response_code(500);
    ktesios_page_open('Ktesios');
    echo '<h1>Aanvragen niet beschikbaar</h1>';
    echo '<p>' . h($error->getMessage()) . '</p>';
    ktesios_page_close();
    exit;
}

$open = [];
$waiting = [];
foreach ($reconciliation['requests'] as $request) {
    $status = (string) ($request['status'] ?? '');
    if ($status === 'open') {
        $open[] = $request;
    } elseif ($status === 'approved') {
        $waiting[] = $request;
    }
}

ktesios_page_open('Ktesios — aanvragen');
echo '<h1>Klantaanvragen</h1>';
echo '<p class="lead">Controle bij laden: ' . h(ktesios_bc_read_source_label()) . '. Alleen goedgekeurde aanvragen die nog niet afgerond zijn.</p>';
echo '<p class="actions"><a class="btn btn-primary" href="new.php">Nieuwe aanvraag</a></p>';
if (!ktesios_can_approve()) {
    echo '<p class="hint">' . h(ktesios_requester_hint()) . '</p>';
}

if ($reconciliation['archivedIds'] !== []) {
    echo '<section class="panel panel-ok">';
    echo '<h2>Afgerond omdat Business Central overeenkomt</h2>';
    echo '<ul>';
    foreach ($reconciliation['archivedIds'] as $archivedId) {
        $href = 'request.php?id=' . rawurlencode((string) $archivedId);
        echo '<li><a href="' . h($href) . '">' . h((string) $archivedId) . '</a></li>';
    }
    echo '</ul></section>';
}

if ($reconciliation['errors'] !== []) {
    echo '<section class="panel panel-warn">';
    echo '<h2>Controle mislukt</h2>';
    echo '<p>Deze aanvragen blijven wachten. Er is niets gearchiveerd en niets overschreven.</p><ul>';
    foreach ($reconciliation['errors'] as $row) {
        echo '<li><a href="request.php?id=' . h(rawurlencode((string) $row['id'])) . '">' . h((string) $row['id']) . '</a> ';
        echo h((string) $row['name']) . ' — ' . h((string) $row['message']) . '</li>';
    }
    echo '</ul></section>';
}

if ($reconciliation['warnings'] !== []) {
    echo '<section class="panel panel-warn">';
    echo '<h2>Business Central wijkt af</h2>';
    echo '<p>De klant bestaat, maar de gegevens verschillen van de goedgekeurde aanvraag. Er is niets overschreven.</p>';
    foreach ($reconciliation['warnings'] as $warning) {
        $href = 'request.php?id=' . rawurlencode((string) $warning['id']);
        echo '<h3><a href="' . h($href) . '">' . h((string) $warning['id']) . '</a> · ' . h((string) $warning['name']);
        $no = trim((string) ($warning['customerNo'] ?? ''));
        if ($no !== '') {
            echo ' · klant ' . h($no);
        }
        echo '</h3>';
        $diff = isset($warning['diff']) && is_array($warning['diff']) ? $warning['diff'] : [];
        ktesios_render_diff_table($diff);
    }
    echo '</section>';
}

echo '<section><h2>Open <span class="count">' . count($open) . '</span></h2>';
ktesios_render_request_table($open, 'Geen open aanvragen.');
echo '</section>';

echo '<section><h2>Goedgekeurd, wacht op Business Central <span class="count">' . count($waiting) . '</span></h2>';
ktesios_render_request_table($waiting, 'Geen aanvragen die op Business Central wachten.');
echo '</section>';

echo '<p class="hint"><a href="archive.php">Naar het archief</a></p>';
ktesios_page_close();
