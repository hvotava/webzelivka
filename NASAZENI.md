# Nasazení webu Vinařství Želivka (Webglobe)

Web je statická stránka (`index.html`) + lehká PHP administrace. Žádná databáze
(MySQL) není potřeba — data o vínech jsou v `data/vina.json`.

## Co nahrát na hosting (FTP/SFTP)

Nahrajte do kořene webu (např. `www/` nebo `public_html/`) tyto soubory a složky:

```
index.html
config.php
objednavka.php
.htaccess
data/vina.json
labels/            (etikety vín — obrázky)
gallery/           (fotky galerie)
hero.jpg
admin/             (index.php, .htaccess)
```

Soubory **nenahrávat**: `.netlify/`, zdrojové PDF etiket, `*.JPG` podklady, `NASAZENI.md`.

## Po nahrání nastavte práva k zápisu

Aby administrace mohla ukládat, musí být zapisovatelné:

- soubor `data/vina.json` (práva 664, případně 666 dle hostingu)
- složka `labels/` (práva 775, případně 777) — kvůli nahrávání etiket
- složka `admin/` (kvůli vytvoření souboru s heslem `admin/.heslo`)

Na Webglobe lze práva nastavit ve Správci souborů nebo přes FTP klienta
(pravý klik → práva / CHMOD).

## Nastavení e-mailů

V `config.php` zkontrolujte:

- `ORDER_EMAIL` — kam chodí objednávky (nyní `bayerova@mjs.narodni.cz`).
- `FROM_EMAIL` — odesílatel automatických e-mailů. **Musí to být adresa na
  vlastní doméně** (např. `web@vinarstvizelivka.cz`), jinak hrozí, že e-maily
  spadnou do spamu. Ověřte s hostingem, že schránka/odesílání existuje.

## První přihlášení do administrace

1. Otevřete `https://vasedomena.cz/admin/`.
2. Při prvním spuštění si nastavíte heslo (uloží se zašifrovaně do `admin/.heslo`).
3. Poté se přihlásíte a můžete spravovat vína (úpravy, ceny, etikety, vyprodáno,
   přidání/smazání, pořadí). Heslo lze později změnit dole na stránce administrace.

## Poznámky

- Obchodní podmínky, ochrana osobních údajů, cookies a věková brána 18+ jsou
  součástí webu. Finální znění obchodních podmínek doporučujeme nechat
  zkontrolovat právníkem.
- Doporučená verze PHP: 8.x (funguje od 7.4).
