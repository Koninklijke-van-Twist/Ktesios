<?php
/**
 * Kopieer naar web/auth.php op de server (niet committen).
 *
 * $canWriteToBC
 *   Alleen het booleaanse true opent het schrijfpad naar Business Central.
 *   Weglaten, false, 1 of de string "true" houden het pad dicht: er wordt
 *   nooit een klant aangemaakt of gewijzigd. Het goedkeuringsscherm toont dan
 *   de payload die anders geschreven zou worden.
 *   Ook mét true doet dit skelet geen live OData-POST. Dat blijft een stub
 *   tot Ariadne de Customer-write invult.
 *
 * $allowedUsers
 *   weglaten of []  → elke geldige Entra-login heeft toegang
 *   lijst met e-mails → alleen die accounts
 *
 * $mimirApi
 *   gezet: het overzicht controleert goedgekeurde aanvragen read-only via Mímir.
 *   leeg: sample-fixtures in web/fixtures/bc_customers.json.
 *   $mimirBase en $mimirCompany zijn optioneel.
 *
 * $baseUrl / $auth zijn placeholders voor de latere service-account-write.
 * Zet hier geen echte productie-wachtwoorden in git. Dit bestand is alleen
 * het sjabloon; de echte auth.php blijft buiten de repository.
 */

$canWriteToBC = false;

// $allowedUsers = [
//     "user@domain.nl",
// ];

// $mimirApi     = 'mimir_…';
// $mimirBase    = 'https://sleutels.kvt.nl/mimir/api';
// $mimirCompany = 'Koninklijke van Twist';

// $baseUrl     = 'https://api.businesscentral.dynamics.com/v2.0/<tenant>/<environment>/ODataV4/';
// $environment = 'Production';
// $auth        = ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'];
