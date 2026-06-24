<?php
/**
 * Administrace Vinařství Želivka.
 * Záložky: Vína, Texty, Galerie. Bez databáze — data v data/*.json,
 * obrázky v labels/ a gallery/.
 */

session_start();
require __DIR__ . '/../config.php';

// ---------- Pomocné funkce ----------
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function load_json($file, $default) {
    if (!is_file($file)) return $default;
    $d = json_decode(file_get_contents($file), true);
    return $d === null ? $default : $d;
}
function save_json($file, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return file_put_contents($file, $json) !== false;
}

function slugify($text) {
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $text = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $text));
    return $text !== '' ? $text : 'soubor';
}
function unique_id($base, $existing) {
    $id = $base; $i = 2;
    while (in_array($id, $existing, true)) { $id = $base . $i; $i++; }
    return $id;
}

/** Zpracuje nahraný obrázek, vrátí relativní cestu (urlPrefix/soubor) nebo null. */
function handle_upload($field, $nameForFile, $destDir, $urlPrefix) {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return null;
    if ($f['size'] > 6 * 1024 * 1024) return null; // max 6 MB
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allowed = ['png', 'jpg', 'jpeg', 'webp'];
    if (!in_array($ext, $allowed, true)) return null;
    if (@getimagesize($f['tmp_name']) === false) return null;
    if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
    $fname = slugify($nameForFile) . '-' . substr(md5(uniqid('', true)), 0, 6) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file($f['tmp_name'], $destDir . '/' . $fname)) return null;
    return $urlPrefix . '/' . $fname;
}

// Pole editovatelných textů (klíč = data-edit atribut na webu).
$TEXT_FIELDS = [
    'hero_tag'        => ['label' => 'Úvod (hero) — štítek nad nadpisem', 'type' => 'text'],
    'hero_title'      => ['label' => 'Úvod (hero) — hlavní nadpis', 'type' => 'text'],
    'hero_text'       => ['label' => 'Úvod (hero) — podtitulek', 'type' => 'textarea'],
    'about_title'     => ['label' => 'O vinařství — nadpis', 'type' => 'text'],
    'about_p1'        => ['label' => 'O vinařství — 1. odstavec', 'type' => 'textarea'],
    'about_p2'        => ['label' => 'O vinařství — 2. odstavec', 'type' => 'textarea'],
    'about_p3'        => ['label' => 'O vinařství — 3. odstavec', 'type' => 'textarea'],
    'degustace_title' => ['label' => 'Degustace — nadpis', 'type' => 'text'],
    'degustace_p1'    => ['label' => 'Degustace — 1. odstavec', 'type' => 'textarea'],
    'degustace_p2'    => ['label' => 'Degustace — 2. odstavec', 'type' => 'textarea'],
];

// ---------- CSRF ----------
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
function csrf_ok() { return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']); }

$err = ''; $msg = '';
$hasPassword = is_file(ADMIN_HASH_FILE) && trim(file_get_contents(ADMIN_HASH_FILE)) !== '';
$tab = $_GET['tab'] ?? 'vina';
if (!in_array($tab, ['vina', 'texty', 'galerie'], true)) $tab = 'vina';

// ---------- Odhlášení ----------
if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: index.php'); exit; }

// ---------- První spuštění: nastavení hesla ----------
if (!$hasPassword) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
        $pw = (string)$_POST['new_password'];
        $pw2 = (string)($_POST['new_password2'] ?? '');
        if (strlen($pw) < 8) $err = 'Heslo musí mít alespoň 8 znaků.';
        elseif ($pw !== $pw2) $err = 'Hesla se neshodují.';
        else {
            if (file_put_contents(ADMIN_HASH_FILE, password_hash($pw, PASSWORD_DEFAULT)) !== false) {
                session_regenerate_id(true);
                $_SESSION['admin'] = true;
                header('Location: index.php');
                exit;
            } else {
                $err = 'Heslo se nepodařilo uložit. Zkontrolujte práva k zápisu ve složce admin/.';
            }
        }
    }
}

// ---------- Přihlášení ----------
$loggedIn = !empty($_SESSION['admin']);
if ($hasPassword && !$loggedIn) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (password_verify((string)$_POST['password'], trim(file_get_contents(ADMIN_HASH_FILE)))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            header('Location: index.php');
            exit;
        } else { $err = 'Nesprávné heslo.'; usleep(500000); }
    }
}

// ---------- Akce po přihlášení ----------
if ($loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== '') {
    if (!csrf_ok()) {
        $err = 'Neplatný bezpečnostní token, zkuste to prosím znovu.';
    } else {
        $action = $_POST['action'];

        if ($action === 'change_password') {
            $cur = (string)($_POST['cur_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');
            $new2 = (string)($_POST['new_password2'] ?? '');
            if (!password_verify($cur, trim(file_get_contents(ADMIN_HASH_FILE)))) $err = 'Stávající heslo není správné.';
            elseif (strlen($new) < 8) $err = 'Nové heslo musí mít alespoň 8 znaků.';
            elseif ($new !== $new2) $err = 'Nová hesla se neshodují.';
            else { file_put_contents(ADMIN_HASH_FILE, password_hash($new, PASSWORD_DEFAULT)); $msg = 'Heslo bylo změněno.'; }
        }

        // --- Uložení vín ---
        if ($action === 'save_vina') {
            $existing = array_column(load_json(DATA_FILE, []), 'id');
            $result = [];
            foreach (($_POST['w'] ?? []) as $i => $row) {
                if (!empty($row['delete'])) continue;
                if (($row['id'] ?? '') === '') continue;
                $img = $row['image'] ?? null;
                $up = handle_upload("imgfile_$i", $row['name'] ?? $row['id'], LABELS_DIR, 'labels');
                if ($up) $img = $up;
                $result[] = [
                    'id' => $row['id'],
                    'name' => trim($row['name'] ?? ''),
                    'params' => trim($row['params'] ?? ''),
                    'desc' => trim($row['desc'] ?? ''),
                    'price' => (int)($row['price'] ?? 0),
                    'badge' => trim($row['badge'] ?? '') !== '' ? trim($row['badge']) : null,
                    'image' => $img,
                    'soldout' => !empty($row['soldout']),
                    '_order' => (int)($row['order'] ?? 999),
                ];
            }
            if (trim($_POST['new']['name'] ?? '') !== '') {
                $n = $_POST['new'];
                $newId = unique_id(slugify($n['name']), array_merge($existing, array_column($result, 'id')));
                $img = handle_upload('new_imgfile', $n['name'], LABELS_DIR, 'labels');
                $result[] = [
                    'id' => $newId, 'name' => trim($n['name']), 'params' => trim($n['params'] ?? ''),
                    'desc' => trim($n['desc'] ?? ''), 'price' => (int)($n['price'] ?? 0),
                    'badge' => trim($n['badge'] ?? '') !== '' ? trim($n['badge']) : null,
                    'image' => $img, 'soldout' => !empty($n['soldout']), '_order' => (int)($n['order'] ?? 999),
                ];
            }
            usort($result, fn($a, $b) => $a['_order'] <=> $b['_order']);
            foreach ($result as &$r) unset($r['_order']); unset($r);
            $msg = save_json(DATA_FILE, $result) ? 'Vína byla uložena.' : 'Uložení selhalo — zkontrolujte práva u data/vina.json.';
            $tab = 'vina';
        }

        // --- Uložení textů ---
        if ($action === 'save_texty') {
            $obsah = load_json(OBSAH_FILE, []);
            foreach ($TEXT_FIELDS as $key => $def) {
                if (isset($_POST['t'][$key])) $obsah[$key] = trim($_POST['t'][$key]);
            }
            $msg = save_json(OBSAH_FILE, $obsah) ? 'Texty byly uloženy.' : 'Uložení selhalo — zkontrolujte práva u data/obsah.json.';
            $tab = 'texty';
        }

        // --- Uložení galerie ---
        if ($action === 'save_galerie') {
            $result = [];
            foreach (($_POST['g'] ?? []) as $i => $row) {
                if (!empty($row['delete'])) continue;
                $img = $row['image'] ?? '';
                $up = handle_upload("gfile_$i", $row['caption'] ?? "foto$i", GALLERY_DIR, 'gallery');
                if ($up) $img = $up;
                if ($img === '') continue;
                $result[] = ['image' => $img, 'caption' => trim($row['caption'] ?? ''), '_order' => (int)($row['order'] ?? 999)];
            }
            // Nová fotka
            $up = handle_upload('new_gfile', $_POST['newg']['caption'] ?? 'foto', GALLERY_DIR, 'gallery');
            if ($up) {
                $result[] = ['image' => $up, 'caption' => trim($_POST['newg']['caption'] ?? ''), '_order' => (int)($_POST['newg']['order'] ?? 999)];
            }
            usort($result, fn($a, $b) => $a['_order'] <=> $b['_order']);
            foreach ($result as &$r) unset($r['_order']); unset($r);
            $msg = save_json(GALERIE_FILE, $result) ? 'Galerie byla uložena.' : 'Uložení selhalo — zkontrolujte práva u data/galerie.json.';
            $tab = 'galerie';
        }
    }
}

$wines   = $loggedIn ? load_json(DATA_FILE, []) : [];
$obsah   = $loggedIn ? load_json(OBSAH_FILE, []) : [];
$galerie = $loggedIn ? load_json(GALERIE_FILE, []) : [];
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Administrace — <?= h(SHOP_NAME) ?></title>
<style>
    * { box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background: #f4f1ea; color: #2c1810; margin: 0; line-height: 1.5; }
    a { color: #b08d3f; }
    .wrap { max-width: 1000px; margin: 0 auto; padding: 1.5rem; }
    .login-wrap { max-width: 420px; margin: 8vh auto; padding: 2rem; background: #fff; border-radius: 8px; box-shadow: 0 12px 40px rgba(0,0,0,0.1); }
    h1 { font-size: 1.5rem; margin: 0 0 0.25rem; }
    h2 { font-size: 1.15rem; margin: 2rem 0 0.75rem; border-bottom: 1px solid #e5ddcf; padding-bottom: 0.4rem; }
    .topbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem; }
    .tabs { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 2px solid #e5ddcf; flex-wrap: wrap; }
    .tab { padding: 0.6rem 1.2rem; text-decoration: none; color: #6b5d4f; font-weight: 600; border-bottom: 2px solid transparent; margin-bottom: -2px; }
    .tab.active { color: #2c1810; border-bottom-color: #b08d3f; }
    label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 0.25rem; color: #6b5d4f; }
    input[type=text], input[type=number], input[type=password], textarea {
        width: 100%; padding: 0.5rem 0.6rem; border: 1px solid #d8cfc0; border-radius: 4px; font: inherit; background: #fff; }
    textarea { min-height: 70px; resize: vertical; }
    .btn { display: inline-block; background: #b08d3f; color: #fff; border: none; padding: 0.6rem 1.2rem; border-radius: 4px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
    .btn:hover { background: #2c1810; }
    .btn-light { background: #fff; color: #2c1810; border: 1px solid #d8cfc0; }
    .btn-sm { padding: 0.35rem 0.7rem; font-size: 0.85rem; }
    .card { background: #fff; border: 1px solid #e5ddcf; border-radius: 8px; padding: 1.25rem; margin-bottom: 1rem; }
    .head { display: flex; gap: 1rem; align-items: flex-start; }
    .thumb { width: 64px; min-width: 64px; height: 90px; object-fit: contain; background: #f4f1ea; border-radius: 4px; }
    .thumb-wide { width: 110px; min-width: 110px; height: 80px; object-fit: cover; background: #f4f1ea; border-radius: 4px; }
    .grid3 { display: grid; grid-template-columns: 90px 1fr 110px; gap: 0.75rem; }
    .field { margin-bottom: 0.75rem; }
    .row-tools { display: flex; gap: 1rem; align-items: center; margin-top: 0.5rem; flex-wrap: wrap; }
    .row-tools label { display: inline-flex; align-items: center; gap: 0.35rem; margin: 0; font-weight: 500; }
    .alert { padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
    .alert-ok { background: #e6f4ea; color: #1e7a3d; border: 1px solid #b7e0c4; }
    .alert-err { background: #fdecea; color: #a4271b; border: 1px solid #f5c6c2; }
    .muted { color: #6b5d4f; font-size: 0.85rem; }
    .sticky-save { position: sticky; bottom: 0; background: #f4f1ea; padding: 1rem 0; border-top: 1px solid #e5ddcf; margin-top: 1rem; }
    details { background: #fff; border: 1px solid #e5ddcf; border-radius: 8px; padding: 1rem 1.25rem; margin-top: 1rem; }
    summary { cursor: pointer; font-weight: 600; }
    @media (max-width: 600px) { .grid3 { grid-template-columns: 1fr; } }
</style>
</head>
<body>

<?php if (!$hasPassword): // ---------- NASTAVENÍ HESLA ---------- ?>
<div class="login-wrap">
    <h1><?= h(SHOP_NAME) ?></h1>
    <p class="muted">Vítejte v administraci. Při prvním spuštění si prosím nastavte heslo.</p>
    <?php if ($err): ?><div class="alert alert-err"><?= h($err) ?></div><?php endif; ?>
    <form method="post">
        <div class="field"><label>Nové heslo (min. 8 znaků)</label><input type="password" name="new_password" required></div>
        <div class="field"><label>Heslo znovu</label><input type="password" name="new_password2" required></div>
        <button class="btn" type="submit">Nastavit heslo a vstoupit</button>
    </form>
</div>

<?php elseif (!$loggedIn): // ---------- PŘIHLÁŠENÍ ---------- ?>
<div class="login-wrap">
    <h1><?= h(SHOP_NAME) ?></h1>
    <p class="muted">Administrace e-shopu</p>
    <?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-err"><?= h($err) ?></div><?php endif; ?>
    <form method="post">
        <div class="field"><label>Heslo</label><input type="password" name="password" required autofocus></div>
        <button class="btn" type="submit">Přihlásit se</button>
    </form>
</div>

<?php else: // ---------- ADMINISTRACE ---------- ?>
<div class="wrap">
    <div class="topbar">
        <div><h1>Administrace</h1><span class="muted"><?= h(SHOP_NAME) ?></span></div>
        <div>
            <a class="btn btn-light btn-sm" href="../index.html" target="_blank">Zobrazit web</a>
            <a class="btn btn-light btn-sm" href="?logout=1">Odhlásit</a>
        </div>
    </div>

    <div class="tabs">
        <a class="tab <?= $tab === 'vina' ? 'active' : '' ?>" href="?tab=vina">Vína</a>
        <a class="tab <?= $tab === 'texty' ? 'active' : '' ?>" href="?tab=texty">Texty</a>
        <a class="tab <?= $tab === 'galerie' ? 'active' : '' ?>" href="?tab=galerie">Galerie</a>
    </div>

    <?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-err"><?= h($err) ?></div><?php endif; ?>

    <?php if ($tab === 'vina'): // ===== VÍNA ===== ?>
    <p class="muted">Upravte údaje vín a klikněte na <strong>Uložit vína</strong>. „Odznak" je štítek vlevo nahoře (např. Novinka). „Pořadí" určuje pořadí na webu (menší = výše).</p>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="save_vina">
        <?php foreach ($wines as $i => $w): ?>
        <div class="card">
            <div class="head">
                <?php if (!empty($w['image'])): ?><img class="thumb" src="../<?= h($w['image']) ?>" alt=""><?php else: ?><div class="thumb"></div><?php endif; ?>
                <div style="flex:1">
                    <input type="hidden" name="w[<?= $i ?>][id]" value="<?= h($w['id']) ?>">
                    <input type="hidden" name="w[<?= $i ?>][image]" value="<?= h($w['image'] ?? '') ?>">
                    <div class="field"><label>Název vína</label><input type="text" name="w[<?= $i ?>][name]" value="<?= h($w['name'] ?? '') ?>"></div>
                    <div class="field"><label>Parametry (alk., cukr, kyseliny)</label><input type="text" name="w[<?= $i ?>][params]" value="<?= h($w['params'] ?? '') ?>"></div>
                    <div class="field"><label>Popis</label><textarea name="w[<?= $i ?>][desc]"><?= h($w['desc'] ?? '') ?></textarea></div>
                    <div class="grid3">
                        <div><label>Cena (Kč)</label><input type="number" name="w[<?= $i ?>][price]" value="<?= h($w['price'] ?? 0) ?>"></div>
                        <div><label>Odznak</label><input type="text" name="w[<?= $i ?>][badge]" value="<?= h($w['badge'] ?? '') ?>"></div>
                        <div><label>Pořadí</label><input type="number" name="w[<?= $i ?>][order]" value="<?= $i + 1 ?>"></div>
                    </div>
                    <div class="field" style="margin-top:0.75rem"><label>Vyměnit etiketu (PNG/JPG, max 6 MB)</label><input type="file" name="imgfile_<?= $i ?>" accept="image/png,image/jpeg,image/webp"></div>
                    <div class="row-tools">
                        <label><input type="checkbox" name="w[<?= $i ?>][soldout]" value="1" <?= !empty($w['soldout']) ? 'checked' : '' ?>> Vyprodáno</label>
                        <label style="color:#a4271b"><input type="checkbox" name="w[<?= $i ?>][delete]" value="1"> Smazat</label>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <h2>Přidat nové víno</h2>
        <div class="card">
            <div class="field"><label>Název vína</label><input type="text" name="new[name]" placeholder="např. Solaris 2024"></div>
            <div class="field"><label>Parametry</label><input type="text" name="new[params]"></div>
            <div class="field"><label>Popis</label><textarea name="new[desc]"></textarea></div>
            <div class="grid3">
                <div><label>Cena (Kč)</label><input type="number" name="new[price]" value="0"></div>
                <div><label>Odznak</label><input type="text" name="new[badge]" placeholder="nepovinné"></div>
                <div><label>Pořadí</label><input type="number" name="new[order]" value="99"></div>
            </div>
            <div class="field" style="margin-top:0.75rem"><label>Etiketa (PNG/JPG, max 6 MB)</label><input type="file" name="new_imgfile" accept="image/png,image/jpeg,image/webp"></div>
            <label style="display:inline-flex;align-items:center;gap:0.35rem;font-weight:500"><input type="checkbox" name="new[soldout]" value="1"> Vyprodáno</label>
            <p class="muted">Vyplňte alespoň název — víno se přidá po uložení.</p>
        </div>
        <div class="sticky-save"><button class="btn" type="submit">Uložit vína</button></div>
    </form>

    <?php elseif ($tab === 'texty'): // ===== TEXTY ===== ?>
    <p class="muted">Upravte texty na webu a klikněte na <strong>Uložit texty</strong>. Změny se projeví ihned po načtení stránky.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="save_texty">
        <div class="card">
            <?php foreach ($TEXT_FIELDS as $key => $def):
                $val = $obsah[$key] ?? ''; ?>
            <div class="field">
                <label><?= h($def['label']) ?></label>
                <?php if ($def['type'] === 'textarea'): ?>
                    <textarea name="t[<?= h($key) ?>]"><?= h($val) ?></textarea>
                <?php else: ?>
                    <input type="text" name="t[<?= h($key) ?>]" value="<?= h($val) ?>">
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="sticky-save"><button class="btn" type="submit">Uložit texty</button></div>
    </form>

    <?php else: // ===== GALERIE ===== ?>
    <p class="muted">Spravujte fotky v galerii na hlavní stránce. „Popisek" se zobrazí pod fotkou, „Pořadí" určuje řazení.</p>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="save_galerie">
        <?php foreach ($galerie as $i => $g): ?>
        <div class="card">
            <div class="head">
                <?php if (!empty($g['image'])): ?><img class="thumb-wide" src="../<?= h($g['image']) ?>" alt=""><?php else: ?><div class="thumb-wide"></div><?php endif; ?>
                <div style="flex:1">
                    <input type="hidden" name="g[<?= $i ?>][image]" value="<?= h($g['image'] ?? '') ?>">
                    <div class="field"><label>Popisek</label><input type="text" name="g[<?= $i ?>][caption]" value="<?= h($g['caption'] ?? '') ?>"></div>
                    <div class="grid3">
                        <div><label>Pořadí</label><input type="number" name="g[<?= $i ?>][order]" value="<?= $i + 1 ?>"></div>
                        <div style="grid-column: span 2"><label>Vyměnit fotku (PNG/JPG, max 6 MB)</label><input type="file" name="gfile_<?= $i ?>" accept="image/png,image/jpeg,image/webp"></div>
                    </div>
                    <div class="row-tools"><label style="color:#a4271b"><input type="checkbox" name="g[<?= $i ?>][delete]" value="1"> Smazat fotku</label></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <h2>Přidat novou fotku</h2>
        <div class="card">
            <div class="field"><label>Fotka (PNG/JPG, max 6 MB)</label><input type="file" name="new_gfile" accept="image/png,image/jpeg,image/webp"></div>
            <div class="field"><label>Popisek</label><input type="text" name="newg[caption]"></div>
            <div class="field"><label>Pořadí</label><input type="number" name="newg[order]" value="99"></div>
            <p class="muted">Fotka se přidá po uložení.</p>
        </div>
        <div class="sticky-save"><button class="btn" type="submit">Uložit galerii</button></div>
    </form>
    <?php endif; ?>

    <details>
        <summary>Změnit heslo do administrace</summary>
        <form method="post" style="margin-top:1rem;max-width:420px">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="change_password">
            <div class="field"><label>Stávající heslo</label><input type="password" name="cur_password" required></div>
            <div class="field"><label>Nové heslo (min. 8 znaků)</label><input type="password" name="new_password" required></div>
            <div class="field"><label>Nové heslo znovu</label><input type="password" name="new_password2" required></div>
            <button class="btn btn-light" type="submit">Změnit heslo</button>
        </form>
    </details>
</div>
<?php endif; ?>

</body>
</html>
