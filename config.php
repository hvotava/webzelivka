<?php
/**
 * Centrální konfigurace webu Vinařství Želivka.
 * Tento soubor pouze definuje konstanty — při přímém otevření v prohlížeči
 * nic nevypíše a navíc je chráněn pravidlem v .htaccess.
 */

// Soubor s nastavením editovatelným z administrace (e-maily apod.).
define('NASTAVENI_FILE', __DIR__ . '/data/nastaveni.json');

// Načtení nastavení z administrace (pokud existuje), jinak výchozí hodnoty níže.
$__nastaveni = [];
if (is_file(NASTAVENI_FILE)) {
    $__n = json_decode(file_get_contents(NASTAVENI_FILE), true);
    if (is_array($__n)) $__nastaveni = $__n;
}

// E-mail, na který chodí objednávky z e-shopu (provozovatel). Mění se v administraci.
define('ORDER_EMAIL', !empty($__nastaveni['order_email']) ? $__nastaveni['order_email'] : 'bayerova@mjs.narodni.cz');

// Odesílatel automatických e-mailů. Mění se v administraci.
define('FROM_EMAIL', !empty($__nastaveni['from_email']) ? $__nastaveni['from_email'] : 'bayerova@mjs.narodni.cz');

// SMTP nastavení pro odeslání e-mailů (volitelné — bez toho se používá mail()).
define('SMTP_SERVER', $__nastaveni['smtp_server'] ?? '');
define('SMTP_PORT', (int)($__nastaveni['smtp_port'] ?? 587));
define('SMTP_LOGIN', $__nastaveni['smtp_login'] ?? '');
define('SMTP_PASSWORD', $__nastaveni['smtp_password'] ?? '');
define('SMTP_TLS', !empty($__nastaveni['smtp_tls']));

// Google Analytics ID (volitelné).
define('GOOGLE_ANALYTICS_ID', $__nastaveni['google_analytics_id'] ?? '');

// Název obchodu (používá se v předmětu a textu e-mailů).
define('SHOP_NAME', 'Vinařství Želivka');

// Datový soubor s víny (čte ho web i administrace).
define('DATA_FILE', __DIR__ . '/data/vina.json');

// Datový soubor s editovatelnými texty webu.
define('OBSAH_FILE', __DIR__ . '/data/obsah.json');

// Datový soubor s fotkami galerie.
define('GALERIE_FILE', __DIR__ . '/data/galerie.json');

// Složka pro nahrané etikety vín.
define('LABELS_DIR', __DIR__ . '/labels');

// Složka pro fotky galerie.
define('GALLERY_DIR', __DIR__ . '/gallery');

// Soubor s otiskem (hashem) hesla do administrace. Vytvoří se při prvním
// spuštění /admin (nastavení hesla). Je chráněn v admin/.htaccess.
define('ADMIN_HASH_FILE', __DIR__ . '/admin/.heslo');
