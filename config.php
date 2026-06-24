<?php
/**
 * Centrální konfigurace webu Vinařství Želivka.
 * Tento soubor pouze definuje konstanty — při přímém otevření v prohlížeči
 * nic nevypíše a navíc je chráněn pravidlem v .htaccess.
 */

// E-mail, na který chodí objednávky z e-shopu (provozovatel).
define('ORDER_EMAIL', 'bayerova@mjs.narodni.cz');

// Odesílatel automatických e-mailů.
define('FROM_EMAIL', 'bayerova@mjs.narodni.cz');

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
