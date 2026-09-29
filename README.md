# Ktesios

Klantaanvraag-portaal voor nieuwe B2B-klanten op sleutels.kvt.nl.
Dit is een aparte app, geen onderdeel van Asclepius.

Pagina-root is **`web/`**. De FTP-deploy spiegelt `web/` naar de remote dir.

## Lokaal

```bash
cp web/auth_TEMPLATE.php web/auth.php
php -S localhost:8765 -t web
```

Open <http://localhost:8765/>.

`web/auth.php` staat in `.gitignore`. Laat `$canWriteToBC = false` staan.
Zonder `$mimirApi` vergelijkt het overzicht met `web/fixtures/bc_customers.json`.
De eerste keer maakt de app `web/data/` aan als die map ontbreekt en kopieert `web/fixtures/requests_seed.json` naar `web/data/requests.json` (ook gitignored).

Lukt de vergrendeling daarna niet, dan toont het overzicht het geprobeerde lockpad (`web/data/requests.json.lock`) en dat die map schrijfbaar moet zijn voor de webserver. Een netwerkschijf of synchronisatiemap weigert `flock` soms terwijl schrijven wel lukt; zet de checkout dan op een lokale schijf. Zonder slot gaat de pagina niet alleen-lezen verder: de controle bij laden schrijft statussen weg, en twee verzoeken zouden `requests.json` anders overschrijven.

Wis `web/data/requests.json` om de voorbeeldlijst terug te zetten.

## Drie gedragingen

### 1. Schrijfgate `$canWriteToBC`

Alleen het booleaanse `true` mag het schrijfpad openen. Weglaten, `false`, `1` of `"true"` houden het dicht.

Staat schrijven uit, dan maakt of wijzigt een goedkeuring **nooit** een klant in Business Central. Na het bevestigingsvenster blijft de aanvraag op *goedgekeurd, wacht op Business Central* en toont het scherm de payload die anders naar de Customer-kaart zou gaan.

Een banner op elke pagina zegt dat schrijven uit staat.

### 2. Controle bij het openen van het overzicht

Voor aanvragen die **goedgekeurd** zijn en **nog niet afgerond**:

| Situatie | Gevolg |
| --- | --- |
| Klant bestaat en de vergeleken velden komen overeen | Aanvraag wordt afgerond (archief) |
| Klant bestaat niet | Blijft wachten; status zichtbaar |
| Klant bestaat maar gegevens wijken af | Waarschuwing, niets overschreven |

Lezen gaat via Mímir (`Customer`) als `$mimirApi` gezet is. Zonder sleutel gelden de sample-fixtures. Een Mímir-fout valt niet terug op die fixtures en archiveert niet.

Het overzicht in de seed laat dit meteen zien: KA-2026-020 komt overeen, KA-2026-030 wijkt af, KA-2026-040 wacht, KA-2026-010 is nog open.

Dezelfde read-only controle draait ook als je een aanvraag opent, zodat de waarschuwing op het detailscherm staat. Het archief controleert niet opnieuw.

### 3. Goedkeuren als schrijven aan staat

De knop **Goedkeuren** opent een bevestigingsvenster (zonder JavaScript: een tussenpagina).

Alleen na die bevestiging, en alleen als `$canWriteToBC === true`, roept de app `ktesios_bc_write_customer` aan en zet de aanvraag op afgerond.

Die functie is een **stub**. Ze logt een dry-run naar `web/data/bc-write.log` en doet geen OData-POST, ook niet als `$baseUrl` en `$auth` gevuld zijn. De bedoelde entiteit en velden staan als placeholder in `web/lib/bc_customer.php` tot Ariadne de echte Customer-write invult. KvK-nummer zit nog niet in de BC-payload.

## Login

`web/logincheck.php` volgt Vulcanus: buiten localhost `require __DIR__ . '/../login/lib.php'` (gedeelde Entra-sessie op `/login/`) en daarna `$allowedUsers`.
`127.0.0.1` en `::1` slaan de login over, zodat de lokale server werkt.

## Goedkeurders

`$approvers` in `web/auth.php` is een lijst e-mailadressen. Alleen die accounts mogen een aanvraag goedkeuren of de gegevens wijzigen. Iedereen die mag inloggen mag een nieuwe aanvraag indienen; goedkeurders ook.

Ontbreekt `$approvers` of is de lijst leeg, dan mag niemand goedkeuren of wijzigen. De knoppen blijven weg en een POST wordt op de server geweigerd.

## Productie

Tim levert op de server (niet in git):

1. **`web/auth.php`** vanuit `auth_TEMPLATE.php`.
2. **`thumbnail.png`** — de tegel op `master` (Tim). Niet overschrijven met een placeholder.

Deploy: `.github/workflows/deploy-ftp.yml` op push naar `master`.

Secrets: `FTP_HOST`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_REMOTE_DIR`.

Verwacht pad:

```text
FTP_REMOTE_DIR=/var/www/html/ktesios
```

De job weigert te deployen als dat secret leeg is of geen pad onder `/var/www/html/…` is (niet de documentroot zelf, geen `..`). `lftp mirror -R --delete` van `./web` slaat `auth.php`, `.htaccess`, `.htpasswd` en de schrijfbare mappen `cache/` en `data/` over.

## Tests

```bash
php tests/can_write_gate_test.php
bash tests/guard_ftp_remote_dir_test.sh
```

De test raakt geen netwerk en leest geen `web/auth.php`.

## Layout

- `web/index.php` — overzicht (open / wachtend) plus de BC-controle
- `web/request.php` — detail, bevestigen, dry-run of stub
- `web/archive.php` — afgerond
- `web/lib/requests_store.php` — JSON in `web/data/`
- `web/lib/bc_customer.php` — leescheck, diff, schrijfgate, stub
- `web/lib/mimir_client.php` — read-only POST naar Mímir `query.php`
