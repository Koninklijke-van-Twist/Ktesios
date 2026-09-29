<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

try {
    $requests = ktesios_load_requests();
} catch (Throwable $error) {
    http_response_code(500);
    ktesios_page_open('Archief');
    echo '<h1>Archief niet beschikbaar</h1><p>' . h($error->getMessage()) . '</p>';
    ktesios_page_close();
    exit;
}

$archived = [];
foreach ($requests as $request) {
    if ((string) ($request['status'] ?? '') === 'archived') {
        $archived[] = $request;
    }
}

ktesios_page_open('Ktesios — archief');
echo '<h1>Archief</h1>';
echo '<p class="lead">Afgeronde en afgekeurde aanvragen. Dit scherm controleert Business Central niet opnieuw.</p>';
ktesios_render_request_table($archived, 'Nog niets afgerond.');
echo '<p class="hint"><a href="index.php">Terug naar aanvragen</a></p>';
ktesios_page_close();
