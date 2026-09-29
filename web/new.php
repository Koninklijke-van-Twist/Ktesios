<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

$error = '';
$draft = ['customer' => [], 'note' => ''];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $draft['customer'] = isset($_POST['customer']) && is_array($_POST['customer']) ? $_POST['customer'] : [];
    $draft['note'] = isset($_POST['note']) && is_string($_POST['note']) ? $_POST['note'] : '';
    $postedCsrf = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
    if ((string) ($_POST['actie'] ?? '') !== 'indienen') {
        $error = 'Onbekende actie.';
    } elseif (!ktesios_csrf_valid($postedCsrf)) {
        $error = 'Deze inzending hoort niet bij je sessie. Laad de pagina opnieuw.';
    } else {
        try {
            $created = ktesios_with_requests_lock(static function (): array {
                $requests = ktesios_load_requests();
                $result = ktesios_create_open_request($requests, $_POST, ktesios_actor(), ktesios_actor_name());
                if ($result['ok'] === true) {
                    ktesios_save_requests($result['requests']);
                }
                return $result;
            });
        } catch (Throwable $failure) {
            http_response_code(500);
            ktesios_page_open('Nieuwe aanvraag');
            echo '<h1>Aanvraag niet opgeslagen</h1><p>' . h($failure->getMessage()) . '</p>';
            ktesios_page_close();
            exit;
        }
        if ($created['ok'] === true) {
            ktesios_csrf_rotate();
            $newId = (string) ($created['request']['id'] ?? '');
            header('Location: request.php?id=' . rawurlencode($newId) . '&ingediend=1', true, 303);
            exit;
        }
        $error = $created['error'] !== '' ? $created['error'] : 'Indienen is niet gelukt.';
    }
}

ktesios_page_open('Nieuwe aanvraag — Ktesios');
echo '<p class="hint"><a href="index.php">← Aanvragen</a></p>';
echo '<h1>Nieuwe klantaanvraag</h1>';
echo '<p class="lead">Iedereen met toegang kan een aanvraag indienen. Goedkeuren, afkeuren en wijzigen doet een aangewezen goedkeurder.</p>';
if ($error !== '') {
    echo '<p class="field-error">' . h($error) . '</p>';
}
echo '<section class="panel">';
ktesios_render_request_form($draft, 'new.php', 'indienen', 'Aanvraag indienen');
echo '</section>';
ktesios_page_close();
