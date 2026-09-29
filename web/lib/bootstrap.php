<?php

declare(strict_types=1);

/**
 * Include dit bestand op topniveau van de pagina, niet vanuit een functie.
 * auth.php zet $canWriteToBC, $allowedUsers, $approvers en $mimirApi. Die moeten globaal
 * blijven, net als bij Vulcanus (require op paginaniveau).
 */

if (!is_file(__DIR__ . '/../auth.php')) {
    require_once __DIR__ . '/layout.php';
    http_response_code(503);
    ktesios_render_setup_page();
    exit;
}

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../logincheck.php';
require_once __DIR__ . '/requests_store.php';
require_once __DIR__ . '/bc_customer.php';
require_once __DIR__ . '/layout.php';
